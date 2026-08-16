<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfFieldCheckControllerTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    /**
     * Requiere que el test que la llama haya invocado Storage::fake('local') PRIMERO —
     * fake() reinicia el disco cada vez que se llama, así que llamarlo aquí adentro
     * borraría el archivo de un enrollEmployee() anterior en el mismo test.
     */
    private function enrollEmployee(int $employeeId, array $embeddingOverride = null): SfEmployeeFaceTemplate
    {
        $photo = UploadedFile::fake()->image('face.jpg', 100, 100)->get();
        $path = "private/sf-face-templates/{$employeeId}.jpg";
        Storage::disk('local')->put($path, $photo);

        return SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employeeId,
            'embedding' => $embeddingOverride ?? array_fill(0, 128, 0.1),
            'photo_path' => $path,
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
    }

    public function test_crew_package_requires_authentication(): void
    {
        $this->getJson('/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id=1')
            ->assertStatus(401);
    }

    public function test_crew_package_returns_only_enrolled_active_employees(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $enrolled = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($enrolled->id);
        $notEnrolled = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $inactive = $this->createSfEmployee($enterprise->id, ['status' => 'inactive']);
        $this->enrollEmployee($inactive->id);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertTrue($ids->contains($enrolled->id));
        $this->assertFalse($ids->contains($notEnrolled->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_crew_package_includes_embedding_and_thumbnail(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id, array_fill(0, 128, 0.25));

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $row = collect($response->json('data.employees'))->firstWhere('id', $employee->id);
        $this->assertCount(128, $row['embedding']);
        $this->assertEqualsWithDelta(0.25, $row['embedding'][0], 0.0001);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $row['thumbnail']);
        $this->assertSame('faceapi-v1', $response->json('data.model_version'));
    }

    public function test_crew_package_excludes_other_enterprises(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [$otherUser, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        $otherEmployee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);
        $this->enrollEmployee($otherEmployee->id);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertFalse($ids->contains($otherEmployee->id));
    }

    public function test_sync_requires_authentication(): void
    {
        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [])
            ->assertStatus(401);
    }

    private function fakeEmbedResponse(): void
    {
        Http::fake([
            '*/embed' => Http::response([
                'embedding' => array_fill(0, 128, 0.1),
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);
    }

    private function tinyJpegBase64(): string
    {
        // JPEG 1x1 válido, suficiente para pasar por el pipeline de storage (el matching real se prueba en Task 4)
        return 'data:image/jpeg;base64,' . base64_encode(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
        ));
    }

    public function test_sync_accepts_new_check_and_dispatches_verification(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.12,
                'manual_override' => false,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $this->assertDatabaseHas('sf_field_checks', ['client_uuid' => $uuid, 'sf_employee_id' => $employee->id]);
        Queue::assertPushed(\App\Jobs\VerifyFieldCheckJob::class);
    }

    public function test_sync_is_idempotent_on_repeated_client_uuid(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $payload = [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ];
        $uuid = $payload['checks'][0]['client_uuid'];

        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', $payload)->assertStatus(200);
        $second = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', $payload);

        $this->assertSame('duplicate', collect($second->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $this->assertSame(1, \App\Models\SfFieldCheck::where('client_uuid', $uuid)->count());
    }

    public function test_sync_accepts_check_without_employee_match(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => null,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertNull($check->sf_employee_id);
        $this->assertSame(\App\Models\SfFieldCheck::STATUS_PENDING, $check->verification_status);
    }

    /**
     * Un dispositivo que estuvo offline 3 horas (checked_at viejo) pero cuyo
     * reloj está correcto (device_synced_at = ahora) NO debe penalizarse con
     * clock_skew_seconds grande — eso es exactamente el caso de uso que la
     * cola offline existe para soportar. checked_at != clock skew.
     */
    public function test_sync_computes_clock_skew_from_device_synced_at_not_checked_at(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        // Captura offline hace 3 horas...
        $offlineCapturedAt = now()->subHours(3)->toIso8601String();
        // ...pero el reloj del dispositivo está correcto justo al sincronizar.
        $currentDeviceClock = now()->toIso8601String();

        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => $offlineCapturedAt,
                'device_synced_at' => $currentDeviceClock,
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ])->assertStatus(200);

        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        // clock_skew_seconds debe ser chico (reloj correcto), aunque checked_at
        // esté horas en el pasado.
        $this->assertLessThan(60, $check->clock_skew_seconds);
        // checked_at se guarda intacto como el timestamp real de asistencia.
        $this->assertEqualsWithDelta(
            \Carbon\Carbon::parse($offlineCapturedAt)->timestamp,
            $check->checked_at->timestamp,
            2
        );
    }

    /**
     * Un dispositivo con el reloj genuinamente mal puesto (device_synced_at
     * muy distinto de la hora real del servidor) SÍ debe producir un
     * clock_skew_seconds grande, sin importar qué tan reciente sea checked_at.
     */
    public function test_sync_detects_genuine_device_clock_skew(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $wrongDeviceClock = now()->addHours(2)->toIso8601String();

        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => $wrongDeviceClock,
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ])->assertStatus(200);

        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertGreaterThanOrEqual(7000, $check->clock_skew_seconds); // ~2 horas en segundos, con margen
    }

    public function test_sync_rejects_employee_from_a_different_enterprise(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [$otherUser, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        $otherEmployee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $otherEmployee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sf_field_checks', ['client_uuid' => $uuid]);
    }

    // ------------------------------------------------------------------
    // Autorización multi-tenant: un usuario autenticado de la Empresa A no
    // debe poder leer/escribir datos de la Empresa B solo por mandar su
    // enterprise_id en el request. Sigue el patrón de fixture de dos
    // empresas de test_crew_package_excludes_other_enterprises, pero fija
    // explícitamente cuál usuario queda "actingAs" para no depender del
    // efecto colateral de la segunda llamada a createAuthenticatedUserWithEnterprise().
    // ------------------------------------------------------------------

    public function test_crew_package_rejects_enterprise_the_user_does_not_belong_to(): void
    {
        Storage::fake('local');
        [$userA, $enterpriseA] = $this->createAuthenticatedUserWithEnterprise();
        // La segunda llamada re-loguea (Sanctum::actingAs) al usuario de la
        // empresa B: el acting user termina siendo $userB, miembro solo de
        // $enterpriseB.
        [$userB, $enterpriseB] = $this->createAuthenticatedUserWithEnterprise();

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterpriseA->id}");

        $response->assertStatus(403);
    }

    public function test_sync_rejects_enterprise_the_user_does_not_belong_to(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$userA, $enterpriseA] = $this->createAuthenticatedUserWithEnterprise();
        $employeeA = $this->createSfEmployee($enterpriseA->id, ['status' => 'active']);
        [$userB, $enterpriseB] = $this->createAuthenticatedUserWithEnterprise();

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterpriseA->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employeeA->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('sf_field_checks', ['client_uuid' => $uuid]);
    }

    public function test_index_rejects_enterprise_the_user_does_not_belong_to(): void
    {
        [$userA, $enterpriseA] = $this->createAuthenticatedUserWithEnterprise();
        $employeeA = $this->createSfEmployee($enterpriseA->id, ['status' => 'active']);
        $this->makeCheckDirectly($employeeA->id, $userA->id, ['enterprise_id' => $enterpriseA->id]);
        [$userB, $enterpriseB] = $this->createAuthenticatedUserWithEnterprise();

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks?enterprise_id={$enterpriseA->id}");

        $response->assertStatus(403);
    }

    public function test_sync_rejects_out_of_range_client_confidence_without_failing_whole_batch(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $badUuid = (string) \Illuminate\Support\Str::uuid();
        $goodUuid = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [
                [
                    'client_uuid' => $badUuid,
                    'sf_employee_id' => $employee->id,
                    'type' => 'check_in',
                    'checked_at' => now()->toIso8601String(),
                    'device_synced_at' => now()->toIso8601String(),
                    'evidence_photo' => $this->tinyJpegBase64(),
                    // decimal(5,4) solo llega hasta 9.9999 — este valor desbordaría la columna.
                    'client_confidence' => 42.5,
                ],
                [
                    'client_uuid' => $goodUuid,
                    'sf_employee_id' => $employee->id,
                    'type' => 'check_out',
                    'checked_at' => now()->toIso8601String(),
                    'device_synced_at' => now()->toIso8601String(),
                    'evidence_photo' => $this->tinyJpegBase64(),
                    'client_confidence' => 0.2,
                ],
            ],
        ]);

        // Nunca un 500: la validación de rango pasa a nivel de item, no de request completo.
        $response->assertStatus(200);

        $results = collect($response->json('data.results'));
        $this->assertSame('rejected', $results->firstWhere('client_uuid', $badUuid)['status']);
        $this->assertSame('accepted', $results->firstWhere('client_uuid', $goodUuid)['status']);

        $this->assertDatabaseMissing('sf_field_checks', ['client_uuid' => $badUuid]);
        $this->assertDatabaseHas('sf_field_checks', ['client_uuid' => $goodUuid]);
        Queue::assertPushed(\App\Jobs\VerifyFieldCheckJob::class, 1);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/splendidfarms/administration/personal/field-checks?enterprise_id=1')
            ->assertStatus(401);
    }

    public function test_index_filters_by_verification_status(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $verified = $this->makeCheckDirectly($employee->id, $user->id, ['enterprise_id' => $enterprise->id, 'verification_status' => 'verified']);
        $mismatch = $this->makeCheckDirectly($employee->id, $user->id, ['enterprise_id' => $enterprise->id, 'verification_status' => 'mismatch']);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks?enterprise_id={$enterprise->id}&verification_status=mismatch");

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($mismatch->id));
        $this->assertFalse($ids->contains($verified->id));
    }

    private function makeCheckDirectly(?int $employeeId, int $checkerId, array $overrides = []): \App\Models\SfFieldCheck
    {
        return \App\Models\SfFieldCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employeeId,
            'checked_by_user_id' => $checkerId,
            'type' => 'check_in',
            'checked_at' => now(),
            'evidence_photo_path' => 'private/sf-field-checks-evidence/fake.jpg',
            'verification_status' => 'pending',
            'manual_override' => false,
        ], $overrides));
    }

    public function test_sync_stores_enterprise_id_even_without_employee_match(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => null,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'device_synced_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0,
            ]],
        ])->assertStatus(200);

        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame($enterprise->id, $check->enterprise_id);
        $this->assertNull($check->sf_employee_id);
    }

    public function test_index_includes_checks_without_employee_match(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $noMatchCheck = $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks?enterprise_id={$enterprise->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($noMatchCheck->id));
    }

    public function test_review_requires_authentication(): void
    {
        // No se usa createAuthenticatedUserWithEnterprise() aquí a propósito:
        // ese helper deja al usuario autenticado (Sanctum::actingAs) como
        // efecto colateral, lo que invalidaría el propósito de este test. Se
        // crean Enterprise/User "a mano" solo para satisfacer las FKs reales
        // de sf_field_checks (enterprise_id, checked_by_user_id — agregadas
        // en la migración de Task 1), sin autenticar a nadie.
        $user = \App\Models\User::factory()->create();
        $enterprise = \App\Models\Enterprise::create([
            'name' => 'Empresa de Prueba',
            'slug' => 'test-review-auth',
            'description' => 'Empresa de prueba',
            'is_active' => true,
        ]);
        $check = $this->makeCheckDirectly(null, $user->id, ['enterprise_id' => $enterprise->id, 'verification_status' => 'no_template']);
        $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", ['decision' => 'approve'])
            ->assertStatus(401);
    }

    public function test_review_rejects_check_from_another_enterprise(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [$otherUser, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly($employee->id, $otherUser->id, [
            'enterprise_id' => $otherEnterprise->id,
            'verification_status' => 'mismatch',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", ['decision' => 'approve'])
            ->assertStatus(403);
    }

    public function test_review_approves_with_already_assigned_employee_and_consolidates(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $checkedAt = now()->setTime(8, 0);
        $check = $this->makeCheckDirectly($employee->id, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'low_confidence',
            'type' => 'check_in',
            'checked_at' => $checkedAt,
        ]);

        $response = $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame('manually_approved', $check->verification_status);
        $this->assertSame($user->id, $check->reviewed_by_user_id);
        $this->assertNotNull($check->reviewed_at);

        $record = \App\Models\SfAttendanceRecord::where('sf_employee_id', $employee->id)
            ->where('date', $checkedAt->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->check_in);
    }

    public function test_review_approves_no_template_check_with_assigned_employee(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $response = $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
            'sf_employee_id' => $employee->id,
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame($employee->id, $check->sf_employee_id);
        $this->assertSame('manually_approved', $check->verification_status);
    }

    public function test_review_reassigns_employee_on_mismatch(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $wrongEmployee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $correctEmployee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly($wrongEmployee->id, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'mismatch',
        ]);

        $response = $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
            'sf_employee_id' => $correctEmployee->id,
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame($correctEmployee->id, $check->sf_employee_id);
        $this->assertSame('manually_approved', $check->verification_status);
    }

    public function test_review_approve_without_employee_and_without_assignment_fails(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $check = $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
        ])->assertStatus(422);
    }

    public function test_review_rejects_never_consolidates(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly($employee->id, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'low_confidence',
        ]);

        $response = $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'reject',
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame('rejected', $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_review_on_already_resolved_check_fails(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly($employee->id, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'verified',
        ]);

        $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
        ])->assertStatus(422);
    }

    public function test_review_rejects_employee_assignment_from_another_enterprise(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        Sanctum::actingAs($user);
        $foreignEmployee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $this->postJson("/api/splendidfarms/administration/personal/field-checks/{$check->id}/review", [
            'decision' => 'approve',
            'sf_employee_id' => $foreignEmployee->id,
        ])->assertStatus(422);
    }
}
