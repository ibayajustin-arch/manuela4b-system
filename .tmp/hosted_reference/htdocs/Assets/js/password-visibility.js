document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.password-visibility-toggle[data-password-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-target'));
            if (!input) return;
            var showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
            var action = showPassword ? 'Hide' : 'Show';
            var description = button.getAttribute('data-password-label') || 'password';
            button.setAttribute('aria-label', action + ' ' + description);
            var label = button.querySelector('span');
            if (label) label.textContent = action;
            var icon = button.querySelector('i');
            if (icon) icon.className = showPassword ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';
        });
    });
});
