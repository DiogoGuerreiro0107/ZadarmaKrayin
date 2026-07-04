@php
    $zadarmaPerson = $person ?? ($lead->person ?? null);
@endphp

@if ($zadarmaPerson && count($zadarmaPerson->contact_numbers ?? []))
    <div class="flex flex-col gap-1">
        @foreach ($zadarmaPerson->contact_numbers as $zadarmaContactNumber)
            <v-zadarma-call-button number="{{ $zadarmaContactNumber['value'] }}"></v-zadarma-call-button>
        @endforeach
    </div>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-zadarma-call-button-template"
        >
            <button
                type="button"
                class="icon-call flex cursor-pointer items-center gap-1 rounded p-1 text-lg text-brandColor transition-all hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-gray-950"
                :disabled="isCalling"
                @click="call"
                ::title="'@lang('zadarma::app.call.button-title')'.replace(':number', number)"
            ></button>
        </script>

        <script type="module">
            app.component('v-zadarma-call-button', {
                template: '#v-zadarma-call-button-template',

                props: {
                    number: {
                        type: String,
                        required: true,
                    },
                },

                data() {
                    return {
                        isCalling: false,
                    };
                },

                methods: {
                    call() {
                        if (! confirm(`@lang('zadarma::app.call.confirm')`.replace(':number', this.number))) {
                            return;
                        }

                        this.isCalling = true;

                        this.$axios.post('{{ route('admin.zadarma.call') }}', { to: this.number })
                            .then((response) => {
                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                            })
                            .catch((error) => {
                                this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });
                            })
                            .finally(() => {
                                this.isCalling = false;
                            });
                    },
                },
            });
        </script>
    @endPushOnce
@endif
