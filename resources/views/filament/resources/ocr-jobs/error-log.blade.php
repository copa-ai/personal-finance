<div class="space-y-4">
    <div>
        <div class="text-sm font-medium text-gray-700">Mensaje</div>
        <pre class="mt-1 whitespace-pre-wrap text-xs text-gray-900">{{ $record->error_message ?? 'Sin mensaje.' }}</pre>
    </div>

    <div>
        <div class="text-sm font-medium text-gray-700">Log completo</div>
        <pre class="mt-1 whitespace-pre-wrap text-xs text-gray-900">{{ $record->error_trace ?? 'Sin traza.' }}</pre>
    </div>
</div>
