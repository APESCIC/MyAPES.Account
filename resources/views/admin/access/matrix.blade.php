@extends('admin.access.layout')

@section('access-content')
    @php
        $canManage = auth()->user()->can('admin.group-mappings.manage');
        $groupLabels = [
            'staff' => __('admin.access.matrix_groups.staff'),
            'volunteer' => __('admin.access.matrix_groups.volunteer'),
            'student' => __('admin.access.matrix_groups.student'),
        ];
    @endphp

    <section class="panel" aria-labelledby="access-matrix-title">
        <h2 id="access-matrix-title">{{ __('admin.access.matrix_title') }}</h2>
        <p class="muted">{{ __('admin.access.matrix_intro') }}</p>

        <form method="post" action="{{ route('admin.access.matrix.update') }}">
            @csrf
            @method('put')
            <table>
                <caption>{{ __('admin.access.matrix_caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.access.matrix_module') }}</th>
                        <th scope="col">{{ __('admin.access.matrix_plugin') }}</th>
                        @foreach($groups as $group)
                            <th scope="col">{{ $groupLabels[$group] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row['sub_core_name'] }}</td>
                        <th scope="row">{{ $row['module_name'] }}</th>
                        @foreach($groups as $group)
                            <td>
                                @if($row['groups'][$group] === null)
                                    <span class="muted">{{ __('admin.access.matrix_not_available') }}</span>
                                @else
                                    <input type="checkbox"
                                           id="matrix-{{ $group }}-{{ $row['key'] }}"
                                           name="allowed[{{ $group }}][{{ $row['key'] }}]"
                                           value="1"
                                           aria-label="{{ __('admin.access.matrix_cell_label', ['plugin' => $row['module_name'], 'module' => $row['sub_core_name'], 'group' => $groupLabels[$group]]) }}"
                                           @checked($row['groups'][$group])
                                           @disabled(! $canManage)>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ 2 + count($groups) }}">{{ __('admin.access.matrix_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>

            @if($canManage && $rows !== [])
                <div class="actions">
                    <button type="submit">{{ __('admin.access.matrix_save') }}</button>
                </div>
            @endif
        </form>
    </section>
@endsection
