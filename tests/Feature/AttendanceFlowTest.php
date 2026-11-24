<?php

namespace Tests\Feature;

use App\Course;
use App\Lecturer;
use App\Qr_code;
use App\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Smoke tests for the main lecturer (web) and student (API) flows,
 * guarding the PHP 8.2+ / Laravel 12 upgrade.
 */
class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $generatedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function lecturer(): User
    {
        $user = User::factory()->lecturer()->create();
        Lecturer::create(['user_id' => $user->id, 'name' => $user->name]);

        return $user->fresh();
    }

    public function test_homepage_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_lecturer_can_register_and_log_in(): void
    {
        $this->post('/register', [
            'name' => 'Ama Mensah',
            'email' => 'ama@example.test',
            'password' => 'secret123',
        ])->assertRedirect('/login');

        $this->post('/login', [
            'email' => 'ama@example.test',
            'password' => 'secret123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_lecturer_can_add_courses(): void
    {
        $this->actingAs($this->lecturer())
            ->post('/dashboard/addcourses', [
                'course_code' => ['CSC101', 'CSC102'],
                'course_name' => ['Intro to CS', 'Data Structures'],
            ])
            ->assertSessionHas('message');

        $this->assertSame(2, Course::count());
    }

    public function test_add_courses_rejects_non_array_input_instead_of_crashing(): void
    {
        // On PHP 8, count() on a string throws a TypeError; validation must catch it first.
        $this->actingAs($this->lecturer())
            ->post('/dashboard/addcourses', [
                'course_code' => 'CSC101',
                'course_name' => 'Intro to CS',
            ])
            ->assertSessionHasErrors('course_code');

        $this->assertSame(0, Course::count());
    }

    public function test_lecturer_can_generate_a_qr_code(): void
    {
        $user = $this->lecturer();
        $course = $user->lecturer->courses()->create(['course_code' => 'CSC101', 'course_name' => 'Intro']);

        $this->actingAs($user)
            ->post('/dashboard/generatecode', ['time' => '23:59', 'course_code' => $course->id])
            ->assertRedirect('dashboard/qr-code');

        $code = Qr_code::firstOrFail();
        $file = public_path('images/codes/' . $code->getRawOriginal('image'));
        $this->generatedFiles[] = $file;

        $this->assertFileExists($file);
        $this->assertStringContainsString('<svg', file_get_contents($file));

        $this->actingAs($user)->get('/dashboard/qr-codes')->assertOk()->assertSee($code->image);
    }

    public function test_student_can_register_via_api_and_record_attendance(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        Artisan::call('passport:keys', ['--force' => true]);
        app(ClientRepository::class)->createPersonalAccessClient(null, 'Test Personal Access Client', 'http://localhost');

        $this->postJson('/api/register', [
            'index' => '1234567',
            'name' => 'Kofi Asare',
            'email' => 'kofi@example.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('status', 'success');

        $lecturer = $this->lecturer();
        $course = $lecturer->lecturer->courses()->create(['course_code' => 'CSC101', 'course_name' => 'Intro']);
        $code = Qr_code::create([
            'lecturer_id' => $lecturer->lecturer->id,
            'image' => 'test.svg',
            'course_id' => $course->id,
            'time' => '12:00',
        ]);

        Passport::actingAs(User::where('email', 'kofi@example.test')->firstOrFail());

        $this->postJson('/api/records', ['qr_code' => $code->id])
            ->assertOk()->assertJsonPath('message', 'attendance recorded');

        $this->postJson('/api/records', ['qr_code' => $code->id])
            ->assertJsonPath('message', 'attendance already taken');

        $this->getJson('/api/records')
            ->assertOk()
            ->assertJsonPath('data.0.course.code', 'CSC101');
    }
}
