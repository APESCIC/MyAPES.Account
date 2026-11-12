{{-- Connection test result (#771, #772): each step, then what failed and what to try. Rendered server-side, so it works without JavaScript. --}}
@if($report !== null)
    @php($details = $report->details)
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
                <p data-failure-hint>{{ __('admin.emails.import.what_to_try') }} {{ $report->failure->hint() }}</p>
                @if($report->failure === \App\Core\Mail\Inbound\Diagnostics\FailureType::FolderNotFound && ($details['folders'] ?? []) !== [])
                    <p data-server-folders>{{ __('admin.emails.import.server_folders', ['folders' => implode(', ', $details['folders'])]) }}</p>
                @endif
            @else
                <dl class="detail-list" data-mailbox-details>
                    @isset($details['messages'])
                        <div>
                            <dt>{{ __('admin.emails.import.details.messages') }}</dt>
                            <dd data-detail="messages">{{ __('admin.emails.import.details.messages_value', ['count' => $details['messages'], 'unseen' => $details['unseen'] ?? 0]) }}</dd>
                        </div>
                    @endisset
                    @if(($details['capabilities'] ?? []) !== [])
                        <div>
                            <dt>{{ __('admin.emails.import.details.capabilities') }}</dt>
                            <dd data-detail="capabilities">{{ collect(['IDLE', 'MOVE', 'UIDPLUS'])->map(fn (string $feature): string => $feature.': '.(in_array($feature, $details['capabilities'], true) ? __('admin.emails.import.details.yes') : __('admin.emails.import.details.no')))->implode(', ') }}</dd>
                        </div>
                    @endif
                    @if(! empty($details['certificate_name']))
                        <div>
                            <dt>{{ __('admin.emails.import.details.certificate') }}</dt>
                            <dd data-detail="certificate">
                                {{ ! empty($details['certificate_expires_at'])
                                    ? __('admin.emails.import.details.certificate_value', ['name' => $details['certificate_name'], 'date' => \Illuminate\Support\Carbon::createFromTimestamp($details['certificate_expires_at'])->format('Y-m-d')])
                                    : $details['certificate_name'] }}
                            </dd>
                        </div>
                    @endif
                </dl>
                @foreach($report->warnings() as $warning)
                    <p data-warning="{{ $warning->value }}"><strong>{{ $warning->message() }}</strong> {{ __("admin.emails.import.errors.{$warning->value}.hint", ['features' => implode(', ', $details['missing_features'] ?? [])]) }}</p>
                @endforeach
            @endif

            <p class="muted">{{ __('admin.emails.import.result_total', ['ms' => $report->milliseconds()]) }}</p>
        </div>
    </section>
@endif
