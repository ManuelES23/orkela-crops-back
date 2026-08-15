<?php
// sentinel-back/tests/Unit/FaceMatchingServiceTest.php
namespace Tests\Unit;

use App\Services\FaceMatchingService;
use InvalidArgumentException;
use Tests\TestCase;

class FaceMatchingServiceTest extends TestCase
{
    private FaceMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FaceMatchingService();
        config(['biometrics.match_threshold' => 0.5]);
    }

    public function test_identical_vectors_have_zero_distance(): void
    {
        $vector = array_fill(0, 128, 0.3);
        $this->assertSame(0.0, $this->service->euclideanDistance($vector, $vector));
    }

    public function test_distance_matches_known_calculation(): void
    {
        $a = array_fill(0, 128, 0.0);
        $b = array_fill(0, 128, 0.0);
        $b[0] = 3.0;
        $b[1] = 4.0;
        // sqrt(3^2 + 4^2) = 5.0
        $this->assertEqualsWithDelta(5.0, $this->service->euclideanDistance($a, $b), 0.0001);
    }

    public function test_throws_when_vectors_are_not_128_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->euclideanDistance(array_fill(0, 127, 0.0), array_fill(0, 128, 0.0));
    }

    public function test_is_match_uses_configured_threshold(): void
    {
        config(['biometrics.match_threshold' => 0.5]);
        $this->assertTrue($this->service->isMatch(0.49));
        $this->assertTrue($this->service->isMatch(0.5));
        $this->assertFalse($this->service->isMatch(0.51));
    }
}
