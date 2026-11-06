<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class StaffListUxTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function emptyStateIndexes(): array
    {
        return [
            'apes cic tickets' => [
                'apes-cic.tickets.index',
                'No tickets are available to you yet.',
                'When a ticket is shared with you, or you create one, it will appear here.',
            ],
            'shelter tickets' => [
                'shelter.tickets.index',
                'No tickets are available to you yet.',
                'When a ticket is shared with you, or you create one, it will appear here.',
            ],
            'petcare tickets' => [
                'petcare.tickets.index',
                'No tickets are available to you yet.',
                'When a ticket is shared with you, or you create one, it will appear here.',
            ],
            'shelter pets' => [
                'shelter.pets.index',
                'No pet profiles are available yet.',
                'When a pet profile is added, it will appear here.',
            ],
            'petcare pets' => [
                'petcare.pets.index',
                'No pet profiles are available yet.',
                'When a pet profile is added, it will appear here.',
            ],
            'apes cic cases' => [
                'apes-cic.cases.index',
                'No cases are available to you yet.',
                'When a case is shared with you, or you open one, it will appear here.',
            ],
        ];
    }

    #[DataProvider('emptyStateIndexes')]
    public function test_staff_empty_indexes_show_mascot_empty_state(
        string $routeName,
        string $title,
        string $body,
    ): void {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee('mascot-tip--empty', false)
            ->assertSeeText($title)
            ->assertSeeText($body)
            ->assertDontSee('<table>', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listBeforeCreateIndexes(): array
    {
        return [
            'shelter cases' => ['shelter.cases.index'],
            'petcare consultations' => ['petcare.consultations.index'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function clientOnlyCreateIndexes(): array
    {
        return [
            'apes cic tickets' => ['apes-cic.tickets.index'],
            'shelter tickets' => ['shelter.tickets.index'],
            'petcare tickets' => ['petcare.tickets.index'],
            'shelter pets' => ['shelter.pets.index'],
            'petcare pets' => ['petcare.pets.index'],
            'apes cic cases' => ['apes-cic.cases.index'],
            'petcare consultations for non-clinical staff' => ['petcare.consultations.index'],
        ];
    }

    #[DataProvider('clientOnlyCreateIndexes')]
    public function test_staff_indexes_show_the_list_without_a_client_create_form(string $routeName): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee('id="list"', false)
            ->assertDontSee('id="create"', false);
    }

    #[DataProvider('listBeforeCreateIndexes')]
    public function test_staff_indexes_put_list_before_create(string $routeName): void
    {
        $staff = $routeName === 'petcare.consultations.index'
            ? $this->vetStaff()
            : User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $html = $this->actingAs($staff)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee('id="list"', false)
            ->assertSee('id="create"', false)
            ->getContent();

        $listPos = strpos($html, 'id="list"');
        $createPos = strpos($html, 'id="create"');

        $this->assertNotFalse($listPos);
        $this->assertNotFalse($createPos);
        $this->assertLessThan(
            $createPos,
            $listPos,
            "Expected #list before #create on {$routeName}.",
        );
    }
}
