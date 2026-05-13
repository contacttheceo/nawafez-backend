<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    // POST /api/ai/write-listing
    public function writeListing(Request $request): JsonResponse
    {
        $this->validate($request, [
            'section'      => ['required', 'string', 'in:ma,fleet,contracts,jobs,forum'],
            'listing_type' => ['nullable', 'string', 'max:60'],
            'fields'       => ['required', 'array'],
        ]);

        $section     = $request->section;
        $listingType = $request->listing_type ?? '';
        $fields      = array_filter((array) $request->fields);   // remove empty values

        if (empty($fields)) {
            return response()->json(['message' => 'أدخل بيانات الإعلان أولاً لكي يتمكن الذكاء الاصطناعي من الكتابة.'], 422);
        }

        $fieldsText = collect($fields)
            ->map(fn ($v, $k) => "{$k}: {$v}")
            ->implode('، ');

        $sectionNames = [
            'fleet'     => 'أسطول مركبات لوجستية',
            'contracts' => 'عقود ومناقصات لوجستية',
            'ma'        => 'استحواذ ودمج شركات لوجستية',
            'jobs'      => 'وظائف في قطاع اللوجستيك',
            'forum'     => 'منتدى نقاش لوجستي',
        ];
        $sectionAr = $sectionNames[$section] ?? $section;

        $typeText = $listingType ? "نوع الإعلان: {$listingType}" : '';

        $prompt = <<<PROMPT
أنت كاتب إعلانات محترف متخصص في قطاع اللوجستيك السعودي (نقل، مستودعات، توزيع، أسطول، عقود).
مهمتك: كتابة عنوان ووصف احترافي وجذاب لإعلان على منصة نوافذ B2B.

القسم: {$sectionAr}
{$typeText}
بيانات الإعلان: {$fieldsText}

القواعد:
- العنوان بالعربي: قصير وجذاب، أقل من 80 حرف، يذكر أهم ميزة
- العنوان بالإنجليزي: ترجمة احترافية، أقل من 80 حرف
- الوصف بالعربي: 3-4 جمل احترافية، يبرز المميزات، يشجع على التواصل، بأسلوب B2B رسمي
- الوصف بالإنجليزي: ترجمة احترافية للوصف العربي
- لا تخترع بيانات غير موجودة في المعلومات المُعطاة
- الرد بصيغة JSON فقط بدون أي نص إضافي قبله أو بعده

{
  "title_ar": "...",
  "title_en": "...",
  "description_ar": "...",
  "description_en": "..."
}
PROMPT;

        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            return response()->json(['message' => 'خدمة الذكاء الاصطناعي غير مفعّلة حالياً.'], 503);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-goog-api-key' => trim($apiKey),
                    'Content-Type'   => 'application/json',
                ])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 700,
                        'temperature'     => 0.7,
                    ],
                ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Connection Error: ' . $e->getMessage(),
            ], 502);
        }

        if (! $response->successful()) {
            $errBody = $response->json('error.message') ?? $response->body();
            return response()->json([
                'message' => 'Gemini Error (' . $response->status() . '): ' . $errBody,
            ], 502);
        }

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';

        // Extract JSON block from the response
        preg_match('/\{[^{}]*"title_ar"[^{}]*\}/s', $text, $matches);
        $data = isset($matches[0]) ? json_decode($matches[0], true) : null;

        if (! $data || empty($data['title_ar']) || empty($data['description_ar'])) {
            return response()->json(['message' => 'فشل تحليل رد الذكاء الاصطناعي، حاول مرة أخرى.'], 500);
        }

        return response()->json(['data' => $data]);
    }
}
