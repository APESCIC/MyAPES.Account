{{-- Connection test result (#771, #772): each step, then what failed and what to try. --}}
@if($report !== null)
    <section class="panel" aria-labelledby="mailbox-result-heading" data-mailbox-result data-result="{{ $report->ok() ? 'ok' : $report->failure->value }}">
        <h2 id="mailbox-result-heading">{{ __('admin.emails.import.result_heading') }}</h2>
        <div role="status">
            <ol class="detail-list" data-mailbox-steps>
                @foreach($report->steps as $step)
                    <li data-step="{{ $step->step->value }}" data-step-status="{{ $step->status->value }}">
                        <strong>{{ $step->step->label() }}</strong>:
                        {{ $step->status->label() }}
                        @if($step->status !== \App\Core\Mail\Inbound\Diagnostics\StepStatus::Skipped)
                            <span class="muted">({{ __('admin.emails.import.step_time', ['ms' => $step->milliseconds]) }})</span>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if($report->failure !== null)
                <p data-failure-message><strong>{{ $report->failure->message() }}</strong></p>
            @endif
            <p class="muted">{{ __('admin.emails.import.result_total', ['ms' => $report->milliseconds()]) }}</p>
        </div>
    </section>
@endif
