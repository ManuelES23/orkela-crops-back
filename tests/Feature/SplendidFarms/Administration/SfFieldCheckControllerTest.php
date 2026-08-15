<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
}
