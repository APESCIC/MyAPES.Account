<?php

namespace Tests\Unit;

use App\Support\WorkflowLabels;
use InvalidArgumentException;
use Tests\TestCase;

class WorkflowLabelsTest extends TestCase
{
    public function test_statuses_and_priorities_resolve_title_case_from_terms(): void
    {
        $this->assertSame('In Progress', WorkflowLabels::status('in_progress'));
        $this->assertSame('Waiting on User', WorkflowLabels::status('waiting_on_user'));
        $this->assertSame('Under Review', WorkflowLabels::status('under_review'));
        $this->assertSame('Urgent', WorkflowLabels::priority('urgent'));
        $this->assertSame('Not Neutered', WorkflowLabels::label('neutering_status', 'not_neutered'));
        $this->assertSame('Volunteer', WorkflowLabels::label('recruitment_category', 'volunteer'));
    }

    public function test_unknown_values_fall_back_to_a_headline_instead_of_snake_case(): void
    {
        $this->assertSame('On Hold Pending Vet', WorkflowLabels::status('on_hold_pending_vet'));
        $this->assertSame('', WorkflowLabels::status(null));
        $this->assertSame('', WorkflowLabels::priority(''));
    }

    public function test_options_keep_stored_values_as_keys_in_order(): void
    {
        $this->assertSame(
            ['open' => 'Open', 'in_review' => 'In Review', 'closed' => 'Closed'],
            WorkflowLabels::options('status', ['open', 'in_review', 'closed']),
        );
    }

    public function test_every_group_resolves_every_lang_key(): void
    {
        foreach (WorkflowLabels::GROUPS as $group) {
            $terms = __("terms.{$group}");
            $this->assertIsArray($terms, "terms.{$group} is missing.");

            foreach ($terms as $value => $label) {
                $this->assertSame($label, WorkflowLabels::label($group, $value));
            }
        }
    }

    public function test_unknown_groups_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkflowLabels::label('colour', 'red');
    }
}
