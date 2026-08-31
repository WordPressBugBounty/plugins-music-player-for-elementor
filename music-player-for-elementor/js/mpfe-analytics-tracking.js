(function($) {
	if ('undefined' === typeof window.mpfeAnalyticsData || !window.mpfeAnalyticsData.enabled) {
		return;
	}

	var sampleIntervalMilliseconds = 1000;
	var flushThresholdSeconds = 15;
	var regularListenLimitSeconds = 30;
	var backlogDrainDelayMilliseconds = 3000;
	var recoveryBurstChunkLimit = 3;
	var recoveryBurstDelayMilliseconds = 1000;
	var unloadListenLimitSeconds = 300;
	var nonce = window.mpfeAnalyticsData.nonce;
	var analyticsRestUrl = window.mpfeAnalyticsData.analyticsRestUrl || '';
	var restEndpointAvailable = !!analyticsRestUrl;
	var initialNonceRefreshAttempted = false;
	var nonceRefreshInProgress = false;
	var nonceCallbacks = [];
	var boundEntries = [];
	var pagehideRequestQueued = false;
	var isElementorEditMode = function() {
		var elementorFrontend = window.elementorFrontend;

		if (elementorFrontend && 'function' === typeof elementorFrontend.isEditMode) {
			return !!elementorFrontend.isEditMode();
		}

		if (window.elementor && window.elementor.config && 'undefined' !== typeof window.elementor.config.isEditMode) {
			return 'function' === typeof window.elementor.config.isEditMode ? !!window.elementor.config.isEditMode() : !!window.elementor.config.isEditMode;
		}

		return !!(document.body && (document.body.classList.contains('elementor-editor-active') || document.body.classList.contains('elementor-editor-preview')));
	};
	var pruneBoundEntries = function() {
		boundEntries = boundEntries.filter(function(player) {
			var entry = player.$entry.get(0);

			if (entry && entry.isConnected && player.audio && player.audio.isConnected) {
				return true;
			}

			clearListenInterval(player.$entry);
			$(player.audio).off('.mpfeAnalytics');
			player.$entry.removeData('mpfeAnalyticsBound');
			return false;
		});
	};
	var createIdentifier = function() {
		var values;

		var crypto = window.crypto || window.msCrypto;

		if (crypto && crypto.getRandomValues) {
			try {
				values = new Uint32Array(4);
				crypto.getRandomValues(values);
				return Array.prototype.map.call(values, function(value) {
					return ('00000000' + value.toString(16)).slice(-8);
				}).join('');
			} catch (error) {
				// Fall through to the non-crypto fallback when a browser exposes a broken crypto API.
			}
		}

		return ('0000000000000000' + Math.random().toString(16).slice(2) + Date.now().toString(16)).slice(-32);
	};
	var getSessionIdentifier = function() {
		var storageKey = 'mpfeAnalyticsClientId';
		var identifier = '';

		try {
			identifier = window.sessionStorage.getItem(storageKey) || '';
			if (!/^[a-f0-9]{32}$/.test(identifier)) {
				identifier = createIdentifier();
				window.sessionStorage.setItem(storageKey, identifier);
			}
		} catch (error) {
			identifier = createIdentifier();
		}

		return identifier;
	};
	var clientId = getSessionIdentifier();

	var getDurationSeconds = function(audio) {
		if (!audio || !isFinite(audio.duration)) {
			return 0;
		}

		return Math.round(audio.duration);
	};

	var refreshNonce = function(callback) {
		nonceCallbacks.push(callback);

		if (nonceRefreshInProgress) {
			return;
		}

		nonceRefreshInProgress = true;
		$.ajax({
			url: window.mpfeAnalyticsData.ajaxurl,
			type: 'post',
			dataType: 'json',
			data: { action: 'mpfe_get_analytics_nonce' },
			success: function(response) {
				if (response && response.success && response.data && response.data.nonce) {
					nonce = response.data.nonce;
				}
			},
			complete: function() {
				var callbacks = nonceCallbacks.slice(0);
				nonceCallbacks = [];
				nonceRefreshInProgress = false;
				callbacks.forEach(function(queuedCallback) {
					queuedCallback();
				});
			}
		});
	};

	var withInitialNonce = function(callback) {
		if (initialNonceRefreshAttempted) {
			callback();
			return;
		}

		initialNonceRefreshAttempted = true;
		refreshNonce(callback);
	};

	var isNonceFailure = function(xhr) {
		return xhr && 403 === xhr.status && ('-1' === xhr.responseText || !xhr.responseJSON);
	};

	var eventData = function(eventType, $entry, audio, listenSeconds, unload, eventId) {
		return {
			action: 'mpfe_record_analytics_event',
			nonce: nonce,
			event_type: eventType,
			audio_url: $entry.data('mediafile'),
			page_id: window.mpfeAnalyticsData.pageId,
			page_title: window.mpfeAnalyticsData.pageTitle,
			page_url: window.mpfeAnalyticsData.pageUrl,
			listen_seconds: listenSeconds || 0,
			duration_seconds: getDurationSeconds(audio),
			unload: unload ? 1 : 0,
			client_id: clientId,
			event_id: eventId || createIdentifier()
		};
	};

	var postAjaxEvent = function(data, retried, onSuccess, onFailure) {
		data.nonce = nonce;

		$.ajax({
			url: window.mpfeAnalyticsData.ajaxurl,
			type: 'post',
			dataType: 'json',
			data: data,
			success: function() {
				if ('function' === typeof onSuccess) {
					onSuccess();
				}
			},
			error: function(xhr) {
				if (!retried && isNonceFailure(xhr)) {
					refreshNonce(function() {
						postAjaxEvent(data, true, onSuccess, onFailure);
					});
					return;
				}

				if ('function' === typeof onFailure) {
					onFailure(xhr);
				}
			}
		});
	};

	var postRestEvent = function(data, onSuccess, onFailure) {
		$.ajax({
			url: analyticsRestUrl,
			type: 'post',
			dataType: 'json',
			data: data,
			success: function() {
				if ('function' === typeof onSuccess) {
					onSuccess();
				}
			},
			error: function(xhr) {
				if ('function' === typeof onFailure) {
					onFailure(xhr);
				}
			}
		});
	};

	var sendEvent = function(eventType, $entry, audio, listenSeconds, useBeacon, onAccepted, onFailure, eventId) {
		var entry = $entry.get(0);

		if (isElementorEditMode() || !entry || !entry.isConnected || !audio || !audio.isConnected) {
			return false;
		}

		if (!$entry.data('mediafile')) {
			return false;
		}

		onAccepted = 'function' === typeof onAccepted ? onAccepted : function() {};
		var data = eventData(eventType, $entry, audio, listenSeconds, useBeacon, eventId);

		if (useBeacon) {
			if ('undefined' === typeof URLSearchParams) {
				return false;
			}

			var unloadData = new URLSearchParams(data);
			// Beacons cannot inspect a REST error response and therefore cannot safely fall back.
			var analyticsEndpointUrl = window.mpfeAnalyticsData.ajaxurl;

			if (navigator.sendBeacon) {
				try {
					if (true === navigator.sendBeacon(analyticsEndpointUrl, unloadData)) {
						onAccepted();
						return true;
					}
				} catch (error) {
					// Try keepalive fetch when beacon delivery is rejected.
				}
			}

			if ('function' === typeof window.fetch) {
				try {
					var request = window.fetch(analyticsEndpointUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: unloadData,
						keepalive: true
					});

					if (request) {
						if ('function' === typeof request.catch) {
							request.catch(function() {});
						} else if ('function' === typeof request.then) {
							request.then(null, function() {});
						}

						onAccepted();
						return true;
					}
				} catch (error) {
					return false;
				}
			}

			return false;
		}

		var sendAjax = function() {
			withInitialNonce(function() {
				postAjaxEvent(data, false, onAccepted, onFailure);
			});
		};

		if (restEndpointAvailable) {
			postRestEvent(data, onAccepted, function(xhr) {
				if (xhr && 429 === xhr.status) {
					if ('function' === typeof onFailure) {
						onFailure(xhr);
					}
					return;
				}

				restEndpointAvailable = false;
				sendAjax();
			});
		} else {
			sendAjax();
		}

		return true;
	};

	var resetPlaybackBaseline = function($entry, audio) {
		var playbackRate = Number(audio.playbackRate);

		$entry.data('mpfeAnalyticsLastTime', Number(audio.currentTime));
		$entry.data('mpfeAnalyticsLastWallTime', Date.now());
		$entry.data('mpfeAnalyticsPlaybackRate', isFinite(playbackRate) && playbackRate > 0 ? playbackRate : 1);
	};

	var samplePlayback = function($entry, audio) {
		if ($entry.data('mpfeAnalyticsSeeking')) {
			return 0;
		}

		var currentTime = Number(audio.currentTime);
		var lastTime = Number($entry.data('mpfeAnalyticsLastTime'));
		var wallTime = Date.now();
		var lastWallTime = Number($entry.data('mpfeAnalyticsLastWallTime'));

		if (!isFinite(currentTime) || !isFinite(lastTime) || !isFinite(lastWallTime)) {
			resetPlaybackBaseline($entry, audio);
			return 0;
		}

		var mediaDelta = currentTime - lastTime;
		var wallDelta = (wallTime - lastWallTime) / 1000;
		var playbackRate = Number($entry.data('mpfeAnalyticsPlaybackRate'));
		playbackRate = isFinite(playbackRate) && playbackRate > 0 ? playbackRate : 1;
		var expectedMediaDelta = wallDelta * playbackRate;
		var tolerance = Math.max(0.25, expectedMediaDelta * 0.35);

		$entry.data('mpfeAnalyticsLastTime', currentTime);
		$entry.data('mpfeAnalyticsLastWallTime', wallTime);

		if (wallDelta > 0 && mediaDelta > 0 && Math.abs(mediaDelta - expectedMediaDelta) <= tolerance) {
			$entry.data('mpfeAnalyticsPendingSeconds', (Number($entry.data('mpfeAnalyticsPendingSeconds')) || 0) + wallDelta);
			return wallDelta;
		}

		return 0;
	};

	var scheduleBacklogDrain = function($entry, audio) {
		if ($entry.data('mpfeAnalyticsDrainTimer') || audio.paused || audio.ended || document.visibilityState === 'hidden') {
			return;
		}

		var delay = Number($entry.data('mpfeAnalyticsRecoveryBurstRemaining')) > 0 ? recoveryBurstDelayMilliseconds : backlogDrainDelayMilliseconds;

		$entry.data('mpfeAnalyticsDrainTimer', setTimeout(function() {
			$entry.removeData('mpfeAnalyticsDrainTimer');
			if (document.visibilityState === 'hidden') {
				return;
			}
			flushListenTime($entry, audio, true, false);
		}, delay));
	};

	var getUnloadDeliverableSeconds = function($entry, audio) {
		var pending = Number($entry.data('mpfeAnalyticsPendingSeconds')) || 0;
		var reserved = Number($entry.data('mpfeAnalyticsReservedSeconds')) || 0;
		var deliverable = Math.min(Math.floor(pending - reserved), unloadListenLimitSeconds);
		var durationSeconds = getDurationSeconds(audio);

		if (durationSeconds > 0) {
			deliverable = Math.min(deliverable, durationSeconds);
		}

		return deliverable >= 1 ? deliverable : 0;
	};

	var flushListenTime = function($entry, audio, flushAll, useBeacon) {
		var pending = Number($entry.data('mpfeAnalyticsPendingSeconds')) || 0;
		var reserved = Number($entry.data('mpfeAnalyticsReservedSeconds')) || 0;
		var wholeSeconds = Math.floor(pending - reserved);
		// A beacon must never reuse an in-flight AJAX chunk: that chunk is already reserved.
		var request = useBeacon ? null : $entry.data('mpfeAnalyticsListenRequest');

		if (request && (request.inFlight || request.retryExhausted)) {
			return false;
		}

		if (!useBeacon && !request && $entry.data('mpfeAnalyticsDrainTimer')) {
			return false;
		}

		if (!request && (wholeSeconds < 1 || (!flushAll && wholeSeconds < flushThresholdSeconds))) {
			return false;
		}

		var chunk = request ? request.chunk : (useBeacon ? getUnloadDeliverableSeconds($entry, audio) : Math.min(wholeSeconds, regularListenLimitSeconds));

		if (chunk < 1) {
			return false;
		}

		if (!request) {
			request = {
				chunk: chunk,
				eventId: createIdentifier(),
				retries: 0,
				inFlight: false,
				isRecoveryBurst: !useBeacon && Number($entry.data('mpfeAnalyticsRecoveryBurstRemaining')) > 0
			};
			if (!useBeacon) {
				$entry.data('mpfeAnalyticsListenRequest', request);
			}
			$entry.data('mpfeAnalyticsReservedSeconds', reserved + chunk);
		}
		request.inFlight = true;

		var queued = sendEvent('listen_time', $entry, audio, chunk, useBeacon, function() {
			var currentPending = Number($entry.data('mpfeAnalyticsPendingSeconds')) || 0;
			var currentReserved = Number($entry.data('mpfeAnalyticsReservedSeconds')) || 0;
			var nextPending = Math.max(0, currentPending - chunk);
			var nextReserved = Math.max(0, currentReserved - chunk);
			$entry.data('mpfeAnalyticsPendingSeconds', nextPending);
			$entry.data('mpfeAnalyticsReservedSeconds', nextReserved);

			if (!useBeacon) {
				$entry.removeData('mpfeAnalyticsListenRequest');
				if (request.isRecoveryBurst) {
					var burstRemaining = Math.max(0, (Number($entry.data('mpfeAnalyticsRecoveryBurstRemaining')) || 0) - 1);
					if (burstRemaining && Math.floor(nextPending - nextReserved) >= 1) {
						$entry.data('mpfeAnalyticsRecoveryBurstRemaining', burstRemaining);
					} else {
						$entry.removeData('mpfeAnalyticsRecoveryBurstRemaining');
					}
				}
				if (Math.floor(nextPending - nextReserved) >= regularListenLimitSeconds) {
					scheduleBacklogDrain($entry, audio);
				}
			}
		}, function(xhr) {
			if (useBeacon) {
				$entry.data('mpfeAnalyticsReservedSeconds', Math.max(0, (Number($entry.data('mpfeAnalyticsReservedSeconds')) || 0) - chunk));
				return;
			}

			request.inFlight = false;
			if (request.isRecoveryBurst) {
				request.isRecoveryBurst = false;
				$entry.removeData('mpfeAnalyticsRecoveryBurstRemaining');
			}
			request.retries++;
			if (xhr && (400 === xhr.status || 403 === xhr.status || 409 === xhr.status || 429 === xhr.status)) {
				request.retryExhausted = true;
				return;
			}
			if (request.retries <= 3 && !$entry.data('mpfeAnalyticsRetryTimer')) {
				$entry.data('mpfeAnalyticsRetryTimer', setTimeout(function() {
					$entry.removeData('mpfeAnalyticsRetryTimer');
					flushListenTime($entry, audio, true, false);
				}, backlogDrainDelayMilliseconds));
			} else if (request.retries > 3) {
				request.retryExhausted = true;
			}
		}, request.eventId);

		if (!queued) {
			$entry.data('mpfeAnalyticsReservedSeconds', reserved);
			if (!useBeacon) {
				$entry.removeData('mpfeAnalyticsListenRequest');
			}
		}

		return queued;
	};

	var clearListenInterval = function($entry) {
		var intervalId = $entry.data('mpfeAnalyticsInterval');

		if (intervalId) {
			clearInterval(intervalId);
			$entry.removeData('mpfeAnalyticsInterval');
		}
	};

	var resumeFailedListenRequest = function($entry) {
		var request = $entry.data('mpfeAnalyticsListenRequest');

		if (request && request.retryExhausted) {
			request.retryExhausted = false;
			request.retries = 0;
		}
	};

	var clearRecoveryBurst = function($entry) {
		$entry.removeData('mpfeAnalyticsRecoveryBurstRemaining');
	};

	var startListenInterval = function($entry, audio) {
		clearListenInterval($entry);
		resetPlaybackBaseline($entry, audio);

		var intervalId = setInterval(function() {
			if (!$entry.get(0).isConnected || !audio.isConnected) {
				clearListenInterval($entry);
				pruneBoundEntries();
				return;
			}

			if (audio.paused || audio.ended) {
				clearListenInterval($entry);
				return;
			}

			samplePlayback($entry, audio);
			flushListenTime($entry, audio, false, false);
		}, sampleIntervalMilliseconds);

		$entry.data('mpfeAnalyticsInterval', intervalId);
	};

	var bindEntry = function() {
		var $entry = $(this);
		var audio = $entry.find('audio').get(0);

		if (isElementorEditMode() || !audio || $entry.data('mpfeAnalyticsBound')) {
			return;
		}

		$entry.data('mpfeAnalyticsBound', true);
		$entry.data('mpfeAnalyticsPendingSeconds', 0);
		$entry.data('mpfeAnalyticsReservedSeconds', 0);
		boundEntries.push({ $entry: $entry, audio: audio });

		var trackPlay = function() {
			if ($entry.data('mpfeAnalyticsStartedPlaying')) {
				return;
			}

			$entry.data('mpfeAnalyticsStartedPlaying', true);
			resumeFailedListenRequest($entry);
			sendEvent('play', $entry, audio, 0, false);
			startListenInterval($entry, audio);
		};

		$(audio).on('play.mpfeAnalytics', trackPlay);

		$(audio).on('seeking.mpfeAnalytics', function() {
			$entry.data('mpfeAnalyticsSeeking', true);
			resetPlaybackBaseline($entry, audio);
		});

		$(audio).on('seeked.mpfeAnalytics', function() {
			resetPlaybackBaseline($entry, audio);
			$entry.removeData('mpfeAnalyticsSeeking');
		});

		$(audio).on('ratechange.mpfeAnalytics', function() {
			samplePlayback($entry, audio);
			resetPlaybackBaseline($entry, audio);
		});

		$(audio).on('pause.mpfeAnalytics', function() {
			clearRecoveryBurst($entry);
			samplePlayback($entry, audio);
			clearListenInterval($entry);
			flushListenTime($entry, audio, true, false);
			$entry.removeData('mpfeAnalyticsStartedPlaying');

			if (!audio.ended) {
				sendEvent('pause', $entry, audio, 0, false);
			}
		});

		$(audio).on('ended.mpfeAnalytics', function() {
			clearRecoveryBurst($entry);
			samplePlayback($entry, audio);
			clearListenInterval($entry);
			flushListenTime($entry, audio, true, false);
			$entry.removeData('mpfeAnalyticsStartedPlaying');
			sendEvent('completed', $entry, audio, 0, false);
		});

		$(audio).on('loadstart.mpfeAnalytics', function() {
			clearRecoveryBurst($entry);
			samplePlayback($entry, audio);
			flushListenTime($entry, audio, true, false);
			clearListenInterval($entry);
			resetPlaybackBaseline($entry, audio);
		});

		if (!audio.paused && !audio.ended) {
			trackPlay();
		}
	};

	var bindPlayers = function($scope) {
		if (isElementorEditMode()) {
			return;
		}

		pruneBoundEntries();
		var $context = $scope && $scope.length ? $scope : $(document);
		$context.find('.swp_music_player_entry').each(bindEntry);
	};

	var scheduleBindPlayers = function($scope) {
		bindPlayers($scope);
		setTimeout(function() { bindPlayers($scope); }, 250);
	};

	var addElementorHooks = function() {
		if ('undefined' === typeof window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}

		window.elementorFrontend.hooks.addAction('frontend/element_ready/slide-music-player-free.default', scheduleBindPlayers);
		window.elementorFrontend.hooks.addAction('frontend/element_ready/slide-compact-player.default', scheduleBindPlayers);
	};

	$(document).ready(function() { scheduleBindPlayers($(document)); });
	$(window).on('elementor/frontend/init', addElementorHooks);
	$(document).on('visibilitychange.mpfeAnalytics', function() {
		pruneBoundEntries();

		if (isElementorEditMode()) {
			return;
		}

		if (document.visibilityState === 'hidden') {
			boundEntries.forEach(function(player) {
				clearRecoveryBurst(player.$entry);
				if (!player.audio.paused && !player.audio.ended) {
					samplePlayback(player.$entry, player.audio);
				}
				// Beacon/keepalive is used here before lifecycle suspension; only already sampled time is sent.
				flushListenTime(player.$entry, player.audio, true, true);
			});
			return;
		}

		if (document.visibilityState === 'visible') {
			boundEntries.forEach(function(player) {
				resumeFailedListenRequest(player.$entry);
				if (player.audio.paused || player.audio.ended) {
					return;
				}
				// This uses the normal wall-clock/playback-rate/seek tolerance before resuming timers.
				var recoveredSeconds = samplePlayback(player.$entry, player.audio);
				if (recoveredSeconds > regularListenLimitSeconds) {
					player.$entry.data('mpfeAnalyticsRecoveryBurstRemaining', recoveryBurstChunkLimit);
				}
				if (player.$entry.data('mpfeAnalyticsInterval')) {
					resetPlaybackBaseline(player.$entry, player.audio);
				} else {
					startListenInterval(player.$entry, player.audio);
				}
				flushListenTime(player.$entry, player.audio, true, false);
			});
		}
	});
	$(window).on('pageshow.mpfeAnalytics', function(event) {
		var nativeEvent = event && event.originalEvent ? event.originalEvent : event;

		if (!nativeEvent || !nativeEvent.persisted) {
			return;
		}

		pagehideRequestQueued = false;

		pruneBoundEntries();

		if (isElementorEditMode()) {
			return;
		}

		boundEntries.forEach(function(player) {
			resumeFailedListenRequest(player.$entry);
			clearRecoveryBurst(player.$entry);

			if (player.audio.paused || player.audio.ended) {
				return;
			}

			if (player.$entry.data('mpfeAnalyticsInterval')) {
				resetPlaybackBaseline(player.$entry, player.audio);
			} else {
				startListenInterval(player.$entry, player.audio);
			}
		});
	});
	$(window).on('pagehide.mpfeAnalytics', function() {
		pruneBoundEntries();

		if (isElementorEditMode()) {
			return;
		}

		if (pagehideRequestQueued) {
			return;
		}

		boundEntries.forEach(function(player) {
			if (!player.audio.paused && !player.audio.ended) {
				samplePlayback(player.$entry, player.audio);
			}
		});

		var selectedPlayer = null;
		var largestDeliverableSeconds = 0;

		boundEntries.forEach(function(player) {
			var deliverableSeconds = getUnloadDeliverableSeconds(player.$entry, player.audio);

			if (deliverableSeconds > largestDeliverableSeconds) {
				largestDeliverableSeconds = deliverableSeconds;
				selectedPlayer = player;
			}
		});

		if (selectedPlayer) {
			pagehideRequestQueued = flushListenTime(selectedPlayer.$entry, selectedPlayer.audio, true, true);
		}
	});
	addElementorHooks();
})(jQuery);
