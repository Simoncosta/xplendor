<?php

namespace App\Helpers;

class ApiResponse
{
    public static function success($data = null, $message = 'Success', $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error($message = 'Error', $status = 400, $errors = null)
    {
        if ((int) $status === 403) {
            return self::forbidden((string) $message);
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    /**
     * ACL: o ÚNICO formato de um 403 — {success: false, message, reason, errors: null}. O
     * "reason" é um código curto (o do Access no middleware permission; "empresa" quando a
     * empresa não é desta pessoa; "recusado" nos restantes). O ecrã mostra a message tal como vem.
     */
    public static function forbidden(string $message, ?string $reason = null)
    {
        $reason ??= $message === 'Acesso negado: utilizador inválido.' ? \App\Access\Decision::TENANT : 'recusado';

        return response()->json(['success' => false, 'message' => $message, 'reason' => $reason, 'errors' => null], 403);
    }
}
