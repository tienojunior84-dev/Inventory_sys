// Sales entry JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Manual entry calculations
    const manualProduct = document.getElementById('manual_product');
    const manualQuantity = document.getElementById('manual_quantity');
    const manualBulkQuantity = document.getElementById('manual_bulk_quantity');
    const manualUnitPrice = document.getElementById('manual_unit_price');
    const manualStockDisplay = document.getElementById('manual_stock_display');
    const manualAmountDisplay = document.getElementById('manual_amount_display');
    const manualModeIndividual = document.getElementById('manual_mode_individual');
    const manualModeBulk = document.getElementById('manual_mode_bulk');
    const manualQtyUnitsGroup = document.getElementById('manual_qty_units_group');
    const manualQtyBulkGroup = document.getElementById('manual_qty_bulk_group');
    const manualBulkLabel = document.getElementById('manual_bulk_label');
    const manualBulkConversion = document.getElementById('manual_bulk_conversion');
    const manualBulkHint = document.getElementById('manual_bulk_hint');
    
    if (manualProduct) {
        manualProduct.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const price = parseFloat(selectedOption.getAttribute('data-price')) || 0;
                const stock = selectedOption.getAttribute('data-stock') || '0';
                manualStockDisplay.value = stock + ' units available';
                manualUnitPrice.value = price.toLocaleString('en-US', {maximumFractionDigits: 0, useGrouping: false});
                updateManualModeUI();
                calculateManualAmount();
            } else {
                manualStockDisplay.value = 'Select a product';
                manualUnitPrice.value = '';
                manualAmountDisplay.value = '0 XAF';
            }
        });
        
        manualQuantity.addEventListener('input', calculateManualAmount);
        if (manualBulkQuantity) {
            manualBulkQuantity.addEventListener('input', calculateManualAmount);
        }
        if (manualUnitPrice) {
            manualUnitPrice.addEventListener('input', calculateManualAmount);
        }

        if (manualModeIndividual) {
            manualModeIndividual.addEventListener('change', function() {
                updateManualModeUI();
                calculateManualAmount();
            });
        }
        if (manualModeBulk) {
            manualModeBulk.addEventListener('change', function() {
                updateManualModeUI();
                calculateManualAmount();
            });
        }

        function updateManualModeUI() {
            const selectedOption = manualProduct.options[manualProduct.selectedIndex];
            const unitsPerBulk = parseFloat(selectedOption?.getAttribute('data-units-per-bulk')) || 0;
            const bulkLabel = selectedOption?.getAttribute('data-bulk-label') || 'Bulk';
            const isBulk = manualModeBulk && manualModeBulk.checked;

            if (manualQtyUnitsGroup && manualQtyBulkGroup) {
                manualQtyUnitsGroup.style.display = isBulk ? 'none' : 'block';
                manualQtyBulkGroup.style.display = isBulk ? 'block' : 'none';
            }

            if (manualQuantity) {
                manualQuantity.required = !isBulk;
                if (isBulk) {
                    manualQuantity.value = '';
                }
            }

            if (manualBulkQuantity) {
                manualBulkQuantity.required = !!isBulk;
                if (!isBulk) {
                    manualBulkQuantity.value = '';
                }
            }

            if (manualBulkHint) {
                manualBulkHint.style.display = isBulk ? 'block' : 'none';
            }

            if (manualBulkLabel) {
                manualBulkLabel.textContent = bulkLabel || 'Bulk';
            }

            if (manualBulkConversion) {
                if (isBulk && unitsPerBulk > 0) {
                    manualBulkConversion.textContent = '1 ' + (bulkLabel || 'Bulk') + ' = ' + unitsPerBulk + ' units';
                } else if (isBulk && unitsPerBulk <= 0) {
                    manualBulkConversion.textContent = 'This product has no Units Per Bulk set. Bulk mode may not work.';
                } else {
                    manualBulkConversion.textContent = '';
                }
            }
        }
        
        function calculateManualAmount() {
            const selectedOption = manualProduct.options[manualProduct.selectedIndex];
            if (selectedOption.value && manualUnitPrice) {
                const price = parseFloat(manualUnitPrice.value.replace(/,/g, '')) || 0;
                const isBulk = manualModeBulk && manualModeBulk.checked;
                const unitsPerBulk = parseFloat(selectedOption.getAttribute('data-units-per-bulk')) || 0;
                let quantityUnits = 0;

                if (isBulk) {
                    const bulkQty = parseFloat(manualBulkQuantity?.value) || 0;
                    quantityUnits = unitsPerBulk > 0 ? (bulkQty * unitsPerBulk) : 0;
                } else {
                    quantityUnits = parseFloat(manualQuantity.value) || 0;
                }

                const amount = price * quantityUnits;
                manualAmountDisplay.value = amount.toLocaleString('en-US', {maximumFractionDigits: 0}) + ' XAF';
            }
        }

        updateManualModeUI();
    }
    
    // Batch entry
    const batchRows = document.getElementById('batch_rows');
    const addBatchRowBtn = document.getElementById('add_batch_row');
    const batchTotalSpan = document.getElementById('batch_total');
    
    if (addBatchRowBtn) {
        addBatchRowBtn.addEventListener('click', function() {
            const newRow = batchRows.firstElementChild.cloneNode(true);
            newRow.querySelector('.batch-product').value = '';
            newRow.querySelector('.batch-quantity').value = '';
            newRow.querySelector('.batch-amount').value = '';
            newRow.querySelector('.remove-row').style.display = 'block';
            batchRows.appendChild(newRow);
            attachBatchRowListeners(newRow);
        });
        
        // Attach listeners to initial row
        attachBatchRowListeners(batchRows.firstElementChild);
    }
    
    function attachBatchRowListeners(row) {
        const productSelect = row.querySelector('.batch-product');
        const modeSelect = row.querySelector('.batch-mode');
        const quantityInput = row.querySelector('.batch-quantity');
        const bulkQuantityHidden = row.querySelector('.batch-bulk-quantity');
        const bulkInfo = row.querySelector('.batch-bulk-info');
        const priceInput = row.querySelector('.batch-price');
        const amountInput = row.querySelector('.batch-amount');
        const removeBtn = row.querySelector('.remove-row');
        
        function calculateRowAmount() {
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            if (selectedOption && selectedOption.value && priceInput) {
                const price = parseFloat(priceInput.value.replace(/,/g, '')) || 0;
                const mode = modeSelect ? modeSelect.value : 'individual';
                const unitsPerBulk = parseFloat(selectedOption.getAttribute('data-units-per-bulk')) || 0;
                const bulkLabel = selectedOption.getAttribute('data-bulk-label') || 'Bulk';

                let quantityUnits = parseFloat(quantityInput.value) || 0;
                if (mode === 'bulk') {
                    const bulkQty = parseFloat(quantityInput.value) || 0;
                    quantityUnits = unitsPerBulk > 0 ? (bulkQty * unitsPerBulk) : 0;
                    if (bulkQuantityHidden) bulkQuantityHidden.value = bulkQty;
                    if (bulkInfo) {
                        bulkInfo.style.display = 'block';
                        bulkInfo.textContent = (bulkQty || 0) + ' ' + bulkLabel + ' = ' + (quantityUnits || 0) + ' units';
                    }
                } else {
                    if (bulkQuantityHidden) bulkQuantityHidden.value = '';
                    if (bulkInfo) {
                        bulkInfo.style.display = 'none';
                        bulkInfo.textContent = '';
                    }
                }

                const amount = price * quantityUnits;
                amountInput.value = amount.toLocaleString('en-US', {maximumFractionDigits: 0}) + ' XAF';
            } else {
                amountInput.value = '';
            }
            calculateBatchTotal();
        }
        
        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value && priceInput) {
                const defaultPrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
                priceInput.value = defaultPrice.toLocaleString('en-US', {maximumFractionDigits: 0, useGrouping: false});
            }
            calculateRowAmount();
        });

        if (modeSelect) {
            modeSelect.addEventListener('change', calculateRowAmount);
        }
        quantityInput.addEventListener('input', calculateRowAmount);
        if (priceInput) {
            priceInput.addEventListener('input', calculateRowAmount);
        }
        
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                row.remove();
                calculateBatchTotal();
            });
        }
    }
    
    function calculateBatchTotal() {
        let total = 0;
        const amountInputs = document.querySelectorAll('.batch-amount');
        amountInputs.forEach(function(input) {
            const value = input.value.replace(' XAF', '').replace(/,/g, '');
            total += parseFloat(value) || 0;
        });
        batchTotalSpan.textContent = total.toLocaleString('en-US', {maximumFractionDigits: 0});
    }
    
    // Form submissions
    const manualForm = document.getElementById('manualSalesForm');
    if (manualForm) {
        manualForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitSalesForm(this, 'manual');
        });
    }
    
    const batchForm = document.getElementById('batchSalesForm');
    if (batchForm) {
        batchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitSalesForm(this, 'batch');
        });
    }
    
    const excelForm = document.getElementById('excelUploadForm');
    if (excelForm) {
        excelForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitExcelForm(this);
        });
    }
});

function submitSalesForm(form, method) {
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
    
    fetch(form.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Sales recorded successfully!');
            form.reset();
            if (method === 'batch') {
                location.reload();
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to record sales'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

function submitExcelForm(form) {
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    const previewDiv = document.getElementById('excel_preview');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
    
    fetch(form.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.preview) {
                // Show preview
                previewDiv.style.display = 'block';
                previewDiv.innerHTML = data.preview_html;
                
                // Add confirm button
                const confirmBtn = document.createElement('button');
                confirmBtn.className = 'btn btn-success mt-3';
                confirmBtn.innerHTML = '<i class="bi bi-check-circle"></i> Confirm and Process';
                confirmBtn.onclick = function() {
                    confirmExcelUpload(data.file_path);
                };
                previewDiv.appendChild(confirmBtn);
            } else if (data.processed) {
                alert('Sales uploaded and processed successfully!');
                form.reset();
                previewDiv.style.display = 'none';
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to process file'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

function confirmExcelUpload(filePath) {
    fetch('/Inventory_sys/api/sales.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'method=excel_confirm&file_path=' + encodeURIComponent(filePath)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Sales processed successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to process sales'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}
