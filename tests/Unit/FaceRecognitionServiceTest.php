<?php
// sentinel-back/tests/Unit/FaceRecognitionServiceTest.php
namespace Tests\Unit;

use App\Exceptions\FaceRecognitionException;
use App\Services\FaceRecognitionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FaceRecognitionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.face_recognition.url' => 'http://face-service.test',
            'services.face_recognition.token' => 'test-token',
        ]);
    }

    public function test_embed_returns_embedding_and_model_version(): void
    {
        $embedding = array_fill(0, 128, 0.5);
        Http::fake([
            'face-service.test/embed' => Http::response([
                'embedding' => $embedding,
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);

        $result = app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');

        $this->assertCount(128, $result['embedding']);
        $this->assertSame('faceapi-v1', $result['model_version']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_embed_throws_no_face_exception(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response(['error' => 'no_face'], 422),
        ]);

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('no_face', $e->getReason());
        }
    }

    public function test_embed_throws_service_unavailable_when_unreachable(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response(null, 500),
        ]);

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('service_unavailable', $e->getReason());
        }
    }

    public function test_embed_logs_status_and_body_on_401_token_mismatch(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'FaceRecognitionService: respuesta no exitosa del face-service',
                \Mockery::on(function (array $context) {
                    return $context['status'] === 401
                        && str_contains($context['body'], 'unauthorized');
                })
            );

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('service_unavailable', $e->getReason());
        }
    }

    public function test_embed_logs_status_and_body_on_400_invalid_image(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response(['error' => 'invalid_image'], 400),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'FaceRecognitionService: respuesta no exitosa del face-service',
                \Mockery::on(function (array $context) {
                    return $context['status'] === 400
                        && str_contains($context['body'], 'invalid_image');
                })
            );

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('service_unavailable', $e->getReason());
        }
    }

    public function test_embed_logs_status_and_body_on_500_internal_error(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response(['error' => 'internal_error'], 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'FaceRecognitionService: respuesta no exitosa del face-service',
                \Mockery::on(function (array $context) {
                    return $context['status'] === 500
                        && str_contains($context['body'], 'internal_error');
                })
            );

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('service_unavailable', $e->getReason());
        }
    }

    public function test_embed_throws_invalid_response_on_malformed_embedding(): void
    {
        Http::fake([
            'face-service.test/embed' => Http::response([
                'embedding' => [1.0, 2.0], // longitud incorrecta
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);

        try {
            app(FaceRecognitionService::class)->embed('fake-jpeg-bytes');
            $this->fail('Expected FaceRecognitionException');
        } catch (FaceRecognitionException $e) {
            $this->assertSame('invalid_response', $e->getReason());
        }
    }
}
