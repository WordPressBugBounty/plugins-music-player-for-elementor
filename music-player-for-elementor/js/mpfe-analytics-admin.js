(function ($) {
    'use strict';

    $(function () {
        var canvas = document.getElementById('mpfe-analytics-plays-chart');
        var chartData;
        var datasetLabel;
        var labels = [];
        var plays = [];

        if (!canvas || typeof Chart !== 'function' || typeof mpfeAnalyticsAdminData === 'undefined' || !mpfeAnalyticsAdminData) {
            return;
        }

        chartData = mpfeAnalyticsAdminData.chartData;
        datasetLabel = typeof mpfeAnalyticsAdminData.datasetLabel === 'string' && mpfeAnalyticsAdminData.datasetLabel ? mpfeAnalyticsAdminData.datasetLabel : 'Plays';

        if (!Array.isArray(chartData)) {
            return;
        }

        chartData.forEach(function (row) {
            var value;

            if (!row || (typeof row.label !== 'string' && typeof row.label !== 'number')) {
                return;
            }

            value = Number(row.plays);

            if (!isFinite(value)) {
                return;
            }

            labels.push(String(row.label));
            plays.push(Math.max(0, Math.floor(value)));
        });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: datasetLabel,
                    data: plays,
                    borderColor: '#d63678',
                    backgroundColor: 'rgba(214, 54, 120, 0.14)',
                    borderWidth: 2,
                    pointBackgroundColor: '#d63678',
                    pointBorderColor: '#ffffff',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(80, 87, 94, 0.12)'
                        },
                        ticks: {
                            color: '#3c434a',
                            maxRotation: 0,
                            autoSkip: true
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(80, 87, 94, 0.18)'
                        },
                        ticks: {
                            color: '#3c434a',
                            precision: 0
                        }
                    }
                }
            }
        });
    });
}(jQuery));
