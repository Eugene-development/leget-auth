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

            // Resolve recipient email based on domain/license owner
            $adminEmail = $this->resolveRecipientEmail($request->input('source_url'));

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

            // Send email with graceful failure fallback
            try {
                Mail::send('emails.contact-request', $emailData, function ($msg) use ($adminEmail, $request) {
                    $msg->to($adminEmail)
                        ->subject('LEGET — Новая заявка от ' . $request->name);
                });

                Log::info('LEGET: Contact form notification sent successfully', [
                    'to'   => $adminEmail,
                    'from' => $request->email,
                ]);
            } catch (Exception $mailException) {
                Log::warning('LEGET: Contact form notification email sending failed, but request logged', [
                    'to'    => $adminEmail,
                    'from'  => $request->email,
                    'error' => $mailException->getMessage(),
                ]);
            }

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

    /**
     * Send service request notification email to admin
     */
    public function sendServiceRequestNotification(Request $request)
    {
        try {
            Log::info('LEGET: Service request notification received', [
                'name'         => $request->input('name'),
                'service_type' => $request->input('service_type'),
            ]);

            $request->validate([
                'service_type' => 'required|string|max:50',
                'name'         => 'required|string|max:255',
                'phone'        => 'required|string|max:50',
                'message'      => 'nullable|string|max:2000',
                'source_url'   => 'nullable|string|max:500',
                'city'         => 'nullable|string|max:100',
            ]);

            // Human-readable labels for service types
            $labels = [
                'consultation'      => 'Консультация',
                'design-project'    => 'Дизайн-проект',
                'furniture-project' => 'Проектирование мебели',
                'assembly'          => 'Сборка и монтаж',
                'measurement'       => 'Замер помещения',
                'partnership'       => 'Сотрудничество',
            ];

            $serviceType = $request->input('service_type');
            $serviceLabel = $labels[$serviceType] ?? $serviceType;

            // Resolve recipient email based on domain/license owner
            $adminEmail = $this->resolveRecipientEmail($request->input('source_url'));

            // Prepare email content
            $emailData = [
                'client_name'        => $request->name,
                'phone'              => $request->phone,
                'service_type_label' => $serviceLabel,
                'client_message'     => $request->message,
                'source_url'         => $request->source_url,
                'city'               => $request->city,
                'submitted_at'       => now()->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s') . ' (МСК)',
            ];

            // Send email with graceful failure fallback
            try {
                Mail::send('emails.service-request', $emailData, function ($msg) use ($adminEmail, $request, $serviceLabel) {
                    $msg->to($adminEmail)
                        ->subject('LEGET — Заявка [' . $serviceLabel . '] от ' . $request->name);
                });

                Log::info('LEGET: Service request notification sent successfully', [
                    'to'           => $adminEmail,
                    'service_type' => $serviceType,
                ]);
            } catch (Exception $mailException) {
                Log::warning('LEGET: Service request notification email sending failed, but request logged', [
                    'to'           => $adminEmail,
                    'service_type' => $serviceType,
                    'error'        => $mailException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Заявка на услугу успешно отправлена.',
            ]);

        } catch (ValidationException $e) {
            Log::warning('LEGET: Service request validation failed', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors'  => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('LEGET: Service request notification error', [
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

    /**
     * Resolve the recipient email based on the source URL/domain.
     * Fallback to default admin email.
     */
    private function resolveRecipientEmail(?string $sourceUrl): string
    {
        $defaultEmail = env('ADMIN_EMAIL', 'info@leget.ru');

        if (!$sourceUrl) {
            return $defaultEmail;
        }

        try {
            $parsedUrl = parse_url($sourceUrl);
            $host = $parsedUrl['host'] ?? '';
            if (!$host) {
                return $defaultEmail;
            }

            // Normalize host (remove www.)
            $domain = preg_replace('/^www\./', '', strtolower($host));

            // Find license
            $license = \Illuminate\Support\Facades\DB::table('licenses')
                ->where('domain', $domain)
                ->first();

            if ($license) {
                // Find owner/user of the license
                $user = \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $license->user_id)
                    ->first();

                if ($user && !empty($user->email)) {
                    Log::info('Resolved recipient email for domain', [
                        'domain' => $domain,
                        'email'  => $user->email,
                    ]);
                    return $user->email;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error resolving recipient email for service request', [
                'error' => $e->getMessage()
            ]);
        }

        return $defaultEmail;
    }
}

