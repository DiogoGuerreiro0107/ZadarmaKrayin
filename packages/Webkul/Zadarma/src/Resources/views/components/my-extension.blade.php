@php
    $zadarmaUserExtension = \Webkul\Zadarma\Models\UserExtension::where('user_id', $user->id)->first();
@endphp

<div class="flex w-[360px] max-w-full flex-col gap-2 max-md:w-full">
    <x-admin::accordion>
        <x-slot:header>
            <p class="p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                @lang('zadarma::app.my-extension.title')
            </p>
        </x-slot>

        <x-slot:content>
            <v-zadarma-my-extension
                extension="{{ $zadarmaUserExtension?->extension }}"
                outbound-prefix="{{ $zadarmaUserExtension?->outbound_prefix }}"
            ></v-zadarma-my-extension>
        </x-slot>
    </x-admin::accordion>
</div>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-zadarma-my-extension-template"
    >
        <div class="flex flex-col gap-2.5">
            <p class="text-xs text-gray-600 dark:text-gray-300">
                @lang('zadarma::app.my-extension.info')
            </p>

            <x-admin::form.control-group class="!mb-0">
                <x-admin::form.control-group.label>
                    @lang('zadarma::app.my-extension.label')
                </x-admin::form.control-group.label>

                <input
                    type="text"
                    v-model="extensionValue"
                    class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    placeholder="@lang('zadarma::app.my-extension.placeholder')"
                />
            </x-admin::form.control-group>

            <x-admin::form.control-group class="!mb-0">
                <x-admin::form.control-group.label>
                    @lang('zadarma::app.my-extension.prefix-label')
                </x-admin::form.control-group.label>

                <input
                    type="text"
                    v-model="outboundPrefixValue"
                    class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    placeholder="@lang('zadarma::app.my-extension.prefix-placeholder')"
                />

                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                    @lang('zadarma::app.my-extension.prefix-info')
                </p>
            </x-admin::form.control-group>

            <div class="flex justify-end">
                <button
                    type="button"
                    class="secondary-button"
                    :disabled="isSaving"
                    @click="save"
                >
                    @lang('zadarma::app.my-extension.save-btn')
                </button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-zadarma-my-extension', {
            template: '#v-zadarma-my-extension-template',

            props: {
                extension: {
                    type: String,
                    default: '',
                },

                outboundPrefix: {
                    type: String,
                    default: '',
                },
            },

            data() {
                return {
                    extensionValue: this.extension,
                    outboundPrefixValue: this.outboundPrefix,
                    isSaving: false,
                };
            },

            methods: {
                save() {
                    this.isSaving = true;

                    this.$axios.put('{{ route('admin.zadarma.my-extension.update') }}', {
                            extension: this.extensionValue,
                            outbound_prefix: this.outboundPrefixValue,
                        })
                        .then((response) => {
                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                        })
                        .catch((error) => {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });
                        })
                        .finally(() => {
                            this.isSaving = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
