<x-admin::layouts>
    <x-slot:title>
        @lang('zadarma::app.reports.title')
    </x-slot>

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                @lang('zadarma::app.reports.title')
            </p>
        </div>

        <v-zadarma-reports :users='@json($users)'></v-zadarma-reports>
    </div>

    @pushOnce('scripts')
        {{-- Chart.js is only bundled/loaded by the dashboard page's own view
             (packages/Webkul/Admin/src/Resources/views/dashboard/index.blade.php),
             not the global admin app.js — load it here too. --}}
        <script
            type="module"
            src="{{ vite()->asset('js/chart.js') }}"
        ></script>

        <script
            type="text/x-template"
            id="v-zadarma-reports-template"
        >
            <div class="flex flex-col gap-4">
                <div class="box-shadow flex items-center gap-2.5 rounded bg-white p-4 dark:bg-gray-900">
                    <label class="text-sm font-semibold text-gray-800 dark:text-white">
                        @lang('zadarma::app.reports.filter-user')
                    </label>

                    <select
                        v-model="userId"
                        @change="getStats"
                        class="w-64 max-w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">@lang('zadarma::app.reports.all-users')</option>

                        <option
                            v-for="user in users"
                            :key="user.id"
                            :value="user.id"
                        >
                            @{{ user.name }}
                        </option>
                    </select>
                </div>

                <div class="box-shadow flex flex-col gap-2.5 rounded bg-white p-4 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        @lang('zadarma::app.reports.calls-per-day')
                    </p>

                    <canvas id="zadarma_calls_chart"></canvas>
                </div>

                <div class="box-shadow flex flex-col gap-2.5 rounded bg-white p-4 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        @lang('zadarma::app.reports.duration-per-day')
                    </p>

                    <canvas id="zadarma_duration_chart"></canvas>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-zadarma-reports', {
                template: '#v-zadarma-reports-template',

                props: {
                    users: {
                        type: Array,
                        default: () => [],
                    },
                },

                data() {
                    return {
                        userId: '',
                        callsChart: undefined,
                        durationChart: undefined,
                    };
                },

                mounted() {
                    this.getStats();
                },

                methods: {
                    getStats() {
                        this.$axios.get('{{ route('admin.zadarma.reports.data') }}', {
                                params: { user_id: this.userId },
                            })
                            .then((response) => {
                                this.render(response.data);
                            })
                            .catch(() => {});
                    },

                    render(report) {
                        const colors = {
                            inbound: 'rgba(34, 197, 94, 0.8)',
                            outbound: 'rgba(59, 130, 246, 0.8)',
                            unknown: 'rgba(156, 163, 175, 0.8)',
                        };

                        const labels = {
                            inbound: "@lang('zadarma::app.reports.inbound')",
                            outbound: "@lang('zadarma::app.reports.outbound')",
                            unknown: "@lang('zadarma::app.reports.unknown-direction')",
                        };

                        const directions = ['inbound', 'outbound', 'unknown'];

                        if (this.callsChart) {
                            this.callsChart.destroy();
                        }

                        this.callsChart = new Chart(document.getElementById('zadarma_calls_chart'), {
                            type: 'bar',
                            data: {
                                labels: report.labels,
                                datasets: directions.map((direction) => ({
                                    label: labels[direction],
                                    data: report.calls[direction],
                                    backgroundColor: colors[direction],
                                })),
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    x: { stacked: true },
                                    y: { stacked: true, beginAtZero: true },
                                },
                            },
                        });

                        if (this.durationChart) {
                            this.durationChart.destroy();
                        }

                        this.durationChart = new Chart(document.getElementById('zadarma_duration_chart'), {
                            type: 'bar',
                            data: {
                                labels: report.labels,
                                datasets: directions.map((direction) => ({
                                    label: labels[direction],
                                    data: report.duration_minutes[direction],
                                    backgroundColor: colors[direction],
                                })),
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    x: { stacked: true },
                                    y: { stacked: true, beginAtZero: true },
                                },
                            },
                        });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
