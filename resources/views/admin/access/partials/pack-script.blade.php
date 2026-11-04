<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
document.querySelectorAll('[data-pack]').forEach((pack) => {
    pack.addEventListener('change', () => {
        const perms = JSON.parse(pack.dataset.packPermissions || '[]');
        perms.forEach((name) => {
            const input = document.querySelector(`input[name="permissions[]"][value="${name}"]`);
            if (input) {
                input.checked = pack.checked;
            }
        });
    });
});
</script>
