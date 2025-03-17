<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    public function test_api_user_can_import_a_csv_file()
    {

        $this->uploadCsv();

        // Assert the file was stored
        // todo: check why this is not working
        /*$files = Storage::disk('local')->files('imports');
        $this->assertNotEmpty($files);
        $this->assertMatchesRegularExpression('/imports\/\d+_test\.csv/', $files[0]);*/
    }

    /*    public function test_api_rejects_non_csv_files()
        {
            Storage::fake('local');

            $invalidFile = UploadedFile::fake()->create('document.txt', 100);

            $response = $this->actingAs($this->user)->post('/api/v1/import', [
                'file' => $invalidFile,
            ]);

            $response->assertStatus(422); // Validation error
        }*/

    private function uploadCsv(string $content = '')
    {
        // Fake the storage
        Storage::fake('local');

        // Create a fake CSV file
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );

        // Perform the file upload
        $response = $this->actingAs($this->user)->post('/api/v1/import', [
            'file' => $csvFile,
        ]);

        $response->assertStatus(200);

        return $response->json('data');
    }

    public function test_api_user_can_get_scheduled_imports()
    {
        $import = $this->uploadCsv();

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEquals($import['name'], $data[0]['name']);
    }

    public function test_api_user_scheduled_imports_can_complete_successfully()
    {
        $this->uploadCsv();

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($data[0]['num_rows'], $data[0]['progress']);
        $this->assertEquals(0, $data[0]['failed_imports_count']);
    }

    public function test_api_user_scheduled_imports_can_complete_partially()
    {
        $content = "amount,currency,type,party,wallet,category,description,date\n".
            "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
            '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,02/01/2023';
        $this->uploadCsv($content);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEquals(0, $data[0]['failed_imports_count']);
    }

    public function test_api_user_can_get_records_not_uploaded()
    {
        $content = "amount,currency,type,party,wallet,category,description,date\n".
            "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
            '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,02/01/2023';
        $import = $this->uploadCsv($content);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEquals(0, $data[0]['failed_imports_count']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/imports/{$import['id']}/failed");
        $response->assertStatus(200);

        $data = $response->json('data');
        $data = $data['data'];
        $this->assertIsArray($data);
        $this->assertEquals(1, count($data));
        $this->assertEquals('Date must be in the format YYYY-MM-DD', $data[0]['reason']);
    }

    public function test_api_user_can_fix_records_not_uploaded()
    {
        $content = "amount,currency,type,party,wallet,category,description,date\n".
            "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
            '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,02/01/2023';
        $import = $this->uploadCsv($content);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEquals(0, $data[0]['failed_imports_count']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/imports/{$import['id']}/failed");
        $response->assertStatus(200);

        $data = $response->json('data');
        $data = $data['data'];
        $this->assertIsArray($data);
        $this->assertEquals(1, count($data));
        $this->assertEquals('Date must be in the format YYYY-MM-DD', $data[0]['reason']);
        $data[0]['date'] = '2023-01-01';

        $response = $this->actingAs($this->user)->putJson("/api/v1/imports/{$import['id']}/fix", [$data[0]]);
        $response->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(0, $data[0]['failed_imports_count']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/imports/{$import['id']}/failed");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $data = $data['data'];
        $this->assertEquals(0, count($data));
    }

    public function test_api_user_can_partially_fix_records_not_uploaded()
    {
        $content = "amount,currency,type,party,wallet,category,description,date\n".
            "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
            "200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,02/01/2023\n".
            '203,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,02/01/2023';
        $import = $this->uploadCsv($content);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEquals(0, $data[0]['failed_imports_count']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/imports/{$import['id']}/failed");
        $response->assertStatus(200);

        $data = $response->json('data');
        $data = $data['data'];
        $this->assertIsArray($data);
        $this->assertEquals(2, count($data));
        $this->assertEquals('Date must be in the format YYYY-MM-DD', $data[0]['reason']);
        $data[0]['date'] = '2023-01-01';

        $response = $this->actingAs($this->user)->putJson("/api/v1/imports/{$import['id']}/fix", $data);
        $response->assertStatus(206);

        $response = $this->actingAs($this->user)->getJson('/api/v1/imports');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, $data[0]['failed_imports_count']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/imports/{$import['id']}/failed");
        $response->assertStatus(200);

        $data = $response->json('data');
        $data = $data['data'];
        $this->assertIsArray($data);
        $this->assertEquals(1, count($data));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
