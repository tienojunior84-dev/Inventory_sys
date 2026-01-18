// Main JavaScript functions

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Confirm delete actions
function confirmDelete(message = 'Are you sure you want to delete this item?') {
    return confirm(message);
}

// Format currency input
function formatCurrencyInput(input) {
    let value = input.value.replace(/[^\d.]/g, '');
    if (value) {
        value = parseFloat(value).toFixed(2);
    }
    input.value = value;
}

// Calculate sale amount
function calculateSaleAmount(quantity, price) {
    return (parseFloat(quantity) || 0) * (parseFloat(price) || 0);
}

// Format number as currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

// Show loading state
function showLoading(element) {
    element.classList.add('loading');
}

// Hide loading state
function hideLoading(element) {
    element.classList.remove('loading');
}
