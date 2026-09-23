<?php

namespace App\Http\Controllers;

use App\Services\FormMailDelivery;
use App\Services\FormSubmissionService;
use App\Services\SmartCaptchaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    /**
     * Send contact form notification email
     */
    public function sendContactNotification(Request $request, SmartCaptchaService $captcha)
    {
        try {
            // Антибот-проверка: серверная верификация токена SmartCaptcha.
            if (! $captcha->verify($request->input('captcha_token'), $captcha->clientIp($request))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не пройдена проверка безопасности.',
                    'errors' => ['captcha_token' => ['Подтвердите, что вы не робот.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:50',
                'company' => 'nullable|string|max:255',
                'message' => 'required|string|max:5000',
                'source_url' => 'nullable|url:http,https|max:500',
                'form_title' => 'nullable|string|max:500',
                'form_id' => 'nullable|string|max:120|regex:/^[a-zA-Z0-9._-]+$/',
                'submission_key' => 'nullable|uuid',
                'captcha_token' => 'nullable|string',
                'passport_main' => 'prohibited',
                'passport_registration' => 'prohibited',
                'client_photo' => 'prohibited',
                'photos' => 'prohibited',
            ]);

            return $this->accept($request, $validated + ['service_type' => 'contact']);

        } catch (ValidationException $e) {
            Log::warning('LEGET: Contact form validation failed', [
                'fields' => array_keys($e->errors()),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('LEGET: Contact form notification error', [
                'error_type' => class_basename($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось отправить заявку.',
                'errors' => ['general' => ['Произошла ошибка при отправке.']],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send service request notification email to admin
     */
    public function sendServiceRequestNotification(Request $request)
    {
        try {
            $isPartnership = $request->input('service_type') === 'partnership';
            $isSupplier = $isPartnership && in_array($request->input('partnership_status'), ['manufacturer', 'supplier'], true);
            $isInstallment = $request->input('service_type') === 'installment';
            $isWarranty = $request->input('service_type') === 'warranty';
            $isContact = $request->input('service_type') === 'contact';
            $isSubscription = $request->input('service_type') === 'subscription';
            $isCountertopEstimate = $request->input('service_type') === 'countertop-estimate';
            $isSiteConsultation = $request->input('form_id') === 'promo1-contacts-site-consultation';
            $phoneRule = match (true) {
                $isSubscription, $isWarranty => 'nullable|string|max:50',
                $isContact => 'nullable|required_without:email|string|max:50',
                $isSupplier => 'nullable|required_without:email|string|max:50|regex:/^\+?[0-9]{7,15}$/',
                $isPartnership => 'required|string|max:50|regex:/^\+?[0-9]{7,15}$/',
                default => 'required|string|max:50',
            };
            $emailRule = match (true) {
                $isSubscription => 'required|email|max:255',
                $isContact, $isSupplier => 'nullable|required_without:phone|email|max:255',
                default => 'nullable|email|max:255',
            };

            $validated = $request->validate([
                'service_type' => ['required', Rule::in(array_diff(array_keys(config('forms.types')), ['partner-application']))],
                'name' => $request->input('service_type') === 'subscription' ? 'nullable|string|max:255' : 'required|string|max:255',
                'phone' => $phoneRule,
                'email' => $emailRule,
                'company' => $isSupplier ? 'required|string|max:255' : 'nullable|string|max:255',
                'partnership_status' => 'required_if:form_id,promo1-partnership|nullable|in:referral,manufacturer,supplier,other',
                'contract_number' => $isWarranty ? 'required|string|max:100' : 'nullable|string|max:100',
                'message' => 'nullable|string|max:2000',
                'source_url' => 'nullable|url:http,https|max:500',
                'form_title' => 'nullable|string|max:500',
                'form_id' => 'nullable|string|max:120|regex:/^[a-zA-Z0-9._-]+$/',
                'submission_key' => 'nullable|uuid',
                'city' => 'nullable|string|max:100',
                'object_address' => $isSiteConsultation ? 'required|string|min:10|max:500' : 'prohibited',
                'visit_time' => $isSiteConsultation ? 'nullable|string|max:160' : 'prohibited',
                'dimensions' => $isCountertopEstimate ? 'required|array|min:1|max:10' : 'prohibited',
                'dimensions.*' => $isCountertopEstimate ? 'required|string|max:120' : 'prohibited',
                'passport_main' => $isInstallment ? 'required|file|mimes:jpg,jpeg,png,pdf|max:5120' : 'prohibited',
                'passport_registration' => $isInstallment ? 'required|file|mimes:jpg,jpeg,png,pdf|max:5120' : 'prohibited',
                'client_photo' => $isInstallment ? 'required|file|mimes:jpg,jpeg,png,webp|max:5120' : 'prohibited',
                'photos' => $isWarranty ? 'nullable|array|max:3' : 'prohibited',
                'position' => 'nullable|string|max:255',
                'photos.*' => $isWarranty ? 'file|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
            ]);

            if ($isSiteConsultation && $validated['service_type'] !== 'consultation') {
                throw ValidationException::withMessages(['service_type' => 'Неверный тип заявки.']);
            }

            return $this->accept($request, $validated);

        } catch (ValidationException $e) {
            Log::warning('LEGET: Service request validation failed', [
                'fields' => array_keys($e->errors()),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('LEGET: Service request notification error', [
                'error_type' => class_basename($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось отправить заявку.',
                'errors' => ['general' => ['Произошла ошибка при отправке.']],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function accept(Request $request, array $data)
    {
        $row = app(FormSubmissionService::class)->accept($data, $request);
        app(FormMailDelivery::class)->deliver($row->id);
        $row = DB::table('service_requests')->where('id', $row->id)->first();

        return response()->json([
            'success' => true, 'id' => $row->id, 'status' => $row->status,
            'mail_status' => $row->mail_status,
            'message' => 'Заявка принята и сохранена.',
        ], $row->mail_status === 'sent' ? 200 : 202);
    }
}
