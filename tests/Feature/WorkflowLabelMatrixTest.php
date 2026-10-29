<?php

namespace Tests\Feature;

use App\Modules\ModuleCodeStatus;
use App\Support\WorkflowLabels;
use Plugins\Cases\CasesArea;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Http\Controllers\TicketController;
use Tests\TestCase;

class WorkflowLabelMatrixTest extends TestCase
{
    public function test_every_known_workflow_value_has_a_title_case_terms_label(): void
    {
        foreach ($this->matrix() as $source => [$group, $values]) {
            $this->assertNotEmpty($values, "{$source} has no values.");

            foreach ($values as $value) {
                $key = "terms.{$group}.{$value}";

                $this->assertTrue(trans()->has($key), "{$source}: missing {$key}.");
                $this->assertTrue(trans()->has($key, 'en'), "{$source}: missing en fallback {$key}.");

                $label = WorkflowLabels::label($group, $value);
                $this->assertStringNotContainsString('_', $label, "{$source}: {$value} label is snake_case.");
                $this->assertMatchesRegularExpression(
                    '/^[A-Z][a-z]*(?: (?:on|of|and|[A-Z][a-z]*))*$/',
                    $label,
                    "{$source}: '{$label}' is not Title Case.",
                );
            }
        }
    }

    public function test_terms_groups_have_no_unused_or_orphan_values(): void
    {
        $known = [];
        foreach ($this->matrix() as [$group, $values]) {
            $known[$group] = array_merge($known[$group] ?? [], $values);
        }

        foreach (WorkflowLabels::GROUPS as $group) {
            $this->assertEqualsCanonicalizing(
                array_values(array_unique($known[$group] ?? [])),
                array_keys(__("terms.{$group}")),
                "terms.{$group} does not match the stored values the code uses.",
            );
        }
    }

    public function test_module_code_status_labels_are_title_case(): void
    {
        foreach (ModuleCodeStatus::cases() as $status) {
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/', $status->label(), $status->value);
        }
    }

    public function test_plugin_views_never_echo_raw_workflow_values(): void
    {
        $views = [];
        foreach (['tickets', 'cases', 'consultations', 'recruitment', 'pet-profiles'] as $plugin) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path("plugins/{$plugin}/resources/views")));
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $views[] = $file->getPathname();
                }
            }
        }

        $this->assertGreaterThan(20, count($views));

        $raw = '/\{\{\s*\$(?:[a-z]+->)?(status|priority|sex|neutering_status|case_type|category|type|v)\s*\}\}/';
        $this->assertMatchesRegularExpression($raw, '<td>{{ $ticket->status }}</td>');
        $this->assertMatchesRegularExpression($raw, '<option>{{ $v }}</option>');

        foreach ($views as $view) {
            $source = file_get_contents($view);
            $withoutAttributes = preg_replace('/(value|class|data-[a-z-]+)="[^"]*"/', '', $source);

            $this->assertDoesNotMatchRegularExpression($raw, $withoutAttributes, basename(dirname($view)).'/'.basename($view).' echoes a raw workflow value.');
            $this->assertStringNotContainsString("str_replace('_', ' ', \$status)", $source, $view);
        }
    }

    public function test_product_docs_describe_the_title_case_convention(): void
    {
        $product = file_get_contents(base_path('PRODUCT.md'));
        $localisation = file_get_contents(base_path('docs/localisation.md'));

        $this->assertStringNotContainsString('before that glossary lands', $product);
        $this->assertStringContainsString('Title Case', $product);
        $this->assertStringContainsString('### Workflow labels', $localisation);
        $this->assertStringContainsString('WorkflowLabels', $localisation);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private function matrix(): array
    {
        $cic = CasesArea::forModule('apes-cic');
        $shelter = CasesArea::forModule('shelter-rescue');

        return [
            'tickets status' => ['status', $this->inRule('plugins/tickets/src/Http/Controllers/TicketController.php', 'open,in_progress')],
            'tickets priority' => ['priority', TicketController::PRIORITIES],
            'apes cic case status' => ['status', $cic->statuses],
            'apes cic case priority' => ['priority', $cic->priorities],
            'shelter case status' => ['status', $shelter->statuses],
            'shelter case type' => ['case_type', $shelter->caseTypes],
            'consultation status' => ['status', $this->inRule('plugins/consultations/src/Http/Controllers/ConsultationController.php', 'open,in_progress')],
            'recruitment application status' => ['status', RecruitmentApplication::STATUSES],
            'recruitment role status' => ['status', RecruitmentRole::STATUSES],
            'recruitment role category' => ['recruitment_category', RecruitmentRole::CATEGORIES],
            'pet sex' => ['sex', $this->inRule('plugins/pet-profiles/src/Http/Controllers/PetProfileController.php', 'male,')],
            'pet neutering status' => ['neutering_status', $this->inRule('plugins/pet-profiles/src/Http/Controllers/PetProfileController.php', 'neutered,')],
        ];
    }

    /**
     * @return list<string>
     */
    private function inRule(string $path, string $startsWith): array
    {
        $source = file_get_contents(base_path($path));
        $this->assertSame(1, preg_match("/'in:(".preg_quote($startsWith, '/').'[a-z_,]*)\'/', $source, $matches), "No in: rule starting {$startsWith} in {$path}.");

        return explode(',', $matches[1]);
    }
}
