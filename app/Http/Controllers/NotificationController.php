<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Exception;

class NotificationController extends Controller
{
    /**
     * Send contact form notification email
     */
    public function sendContactNotification(Request $request)
    {
        try {
            Log::info('LEGET: Contact form notification received', [
                'name'  => $request->input('name'),
                'email' => $request->input('email'),
            ]);

            $request->validate([
                'name'       => 'required|string|max:255',
                'email'      => 'required|email|max:255',
                'phone'      => 'nullable|string|max:50',
                'company'    => 'nullable|string|max:255',
                'message'    => 'required|string|max:5000',
                'source_url' => 'nullable|string|max:500',
            ]);

            // Get admin email from env
            $adminEmail = env('ADMIN_EMAIL', 'info@leget.ru');

            // Prepare email content
            $emailData = [
                'client_name'    => $request->name,
                'client_email'   => $request->email,
                'phone'          => $request->phone ?? 'Не указан',
                'company'        => $request->company ?? 'Не указана',
                'client_message' => $request->message,
                'source_url'     => $request->source_url ?? 'Не указано',
                'submitted_at'   => now()->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s') . ' (МСК)',
            ];

            // Send email
            Mail::send('emails.contact-request', $emailData, function ($msg) use ($adminEmail, $request) {
                $msg->to($adminEmail)
                    ->subject('LEGET — Новая заявка от ' . $request->name);
            });

            Log::info('LEGET: Contact form notification sent successfully', [
                'to'   => $adminEmail,
                'from' => $request->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно отправлена.',
            ]);

        } catch (ValidationException $e) {
            Log::warning('LEGET: Contact form validation failed', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors'  => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('LEGET: Contact form notification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось отправить заявку.',
                'errors'  => ['general' => ['Произошла ошибка при отправке.']],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
