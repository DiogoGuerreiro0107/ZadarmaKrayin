<?php

namespace Webkul\Zadarma\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Zadarma\Models\UserExtension;

class UserExtensionController
{
    /**
     * Save the currently authenticated user's own Zadarma extension.
     * Deliberately a standalone endpoint (not part of AccountController's
     * update flow), since that flow always requires re-entering the
     * current password.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'extension' => ['nullable', 'string', 'max:20'],
        ]);

        $extension = trim((string) $request->input('extension'));

        if ($extension === '') {
            UserExtension::where('user_id', auth()->id())->delete();

            return response()->json([
                'message' => trans('zadarma::app.my-extension.cleared'),
            ]);
        }

        UserExtension::updateOrCreate(
            ['user_id' => auth()->id()],
            ['extension' => $extension]
        );

        return response()->json([
            'message' => trans('zadarma::app.my-extension.saved'),
        ]);
    }
}
