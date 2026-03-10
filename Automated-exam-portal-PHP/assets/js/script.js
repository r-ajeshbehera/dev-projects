// Ensure all radio buttons are selected before form submission
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const radios = form.querySelectorAll('input[type="radio"]');
            let allChecked = true;
            radios.forEach(radio => {
                if (!form.querySelector(`input[name="${radio.name}"]:checked`)) {
                    allChecked = false;
                }
            });
            if (!allChecked) {
                e.preventDefault();
                alert('Please answer all questions before submitting.');
            }
        });
    });
});