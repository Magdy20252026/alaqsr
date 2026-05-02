document.addEventListener("DOMContentLoaded", function () {
    const themeToggle = document.getElementById("themeToggle");
    const body = document.body;

    function safeJsonParse(element, fallbackValue) {
        if (!element) {
            return fallbackValue;
        }

        try {
            return JSON.parse(element.textContent || "");
        } catch (error) {
            console.error("JSON parse error", error);
            return fallbackValue;
        }
    }

    if (localStorage.getItem("theme") === "dark") {
        body.classList.add("dark-mode");
        if (themeToggle) {
            themeToggle.checked = true;
        }
    }

    if (themeToggle) {
        themeToggle.addEventListener("change", function () {
            if (this.checked) {
                body.classList.add("dark-mode");
                localStorage.setItem("theme", "dark");
            } else {
                body.classList.remove("dark-mode");
                localStorage.setItem("theme", "light");
            }
        });
    }

    const toggleSidebar = document.getElementById("toggleSidebar");
    const sidebar = document.getElementById("sidebar");

    if (toggleSidebar && sidebar) {
        toggleSidebar.addEventListener("click", function () {
            if (window.innerWidth <= 992) {
                sidebar.classList.toggle("mobile-open");
            } else {
                sidebar.classList.toggle("collapsed");
                const mainContent = document.querySelector(".main-content");
                if (mainContent) {
                    if (sidebar.classList.contains("collapsed")) {
                        mainContent.style.marginRight = "0";
                    } else {
                        mainContent.style.marginRight = "310px";
                    }
                }
            }
        });
    }

    const itemPricingType = document.querySelector("[data-item-pricing-type]");
    const itemQuantityField = document.querySelector("[data-item-quantity-field]");

    if (itemPricingType && itemQuantityField) {
        const quantityInput = itemQuantityField.querySelector("input");

        function syncItemPricingFields() {
            const needsQuantity = itemPricingType.value === "quantity_price";
            itemQuantityField.classList.toggle("items-quantity-hidden", !needsQuantity);

            if (quantityInput) {
                quantityInput.required = needsQuantity;

                if (!needsQuantity) {
                    quantityInput.value = "";
                    quantityInput.setCustomValidity("");
                }
            }
        }

        itemPricingType.addEventListener("change", syncItemPricingFields);
        syncItemPricingFields();
    }

    document.querySelectorAll("form[data-confirm-message]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (!window.confirm(form.getAttribute("data-confirm-message") || "هل أنت متأكد؟")) {
                event.preventDefault();
            }
        });
    });

    const cashierItems = document.getElementById("cashierItems");
    const addCashierItem = document.getElementById("addCashierItem");
    const cashierTemplate = document.getElementById("cashierItemTemplate");
    const barberSelect = document.getElementById("cashierBarberSelect");
    const totalElement = document.getElementById("cashierInvoiceTotal");
    const barberShareElement = document.getElementById("cashierBarberShare");
    const salonShareElement = document.getElementById("cashierSalonShare");
    const servicesDataElement = document.getElementById("cashierServicesData");
    const barbersDataElement = document.getElementById("cashierBarbersData");

    if (cashierItems && cashierTemplate && servicesDataElement) {
        const servicesData = safeJsonParse(servicesDataElement, {});
        const barbersData = safeJsonParse(barbersDataElement, {});

        function formatMoney(value) {
            return Number(value || 0).toFixed(2) + " ج";
        }

        function getSelectedBarberPercent() {
            const selectedBarberId = barberSelect ? barberSelect.value : "";
            const selectedBarber = barbersData[selectedBarberId];
            return selectedBarber ? Number(selectedBarber.commission_percent || 0) : 0;
        }

        function updateTotals() {
            let total = 0;

            cashierItems.querySelectorAll("[data-amount-input]").forEach(function (input) {
                total += Number(input.value || 0);
            });

            if (totalElement) {
                totalElement.textContent = formatMoney(total);
            }

            const barberPercent = getSelectedBarberPercent();
            const barberShare = total * (barberPercent / 100);
            const salonShare = total - barberShare;

            if (barberShareElement) {
                barberShareElement.textContent = formatMoney(barberShare);
            }

            if (salonShareElement) {
                salonShareElement.textContent = formatMoney(salonShare);
            }
        }

        function updateRemoveButtons() {
            const rows = cashierItems.querySelectorAll("[data-cashier-item]");
            rows.forEach(function (row) {
                const removeButton = row.querySelector("[data-remove-item]");
                if (removeButton) {
                    removeButton.disabled = rows.length === 1;
                }
            });
        }

        function updateRow(row, shouldOverwriteAmount) {
            const serviceSelect = row.querySelector("[data-service-select]");
            const amountInput = row.querySelector("[data-amount-input]");
            const basePriceDisplay = row.querySelector("[data-base-price-display]");
            const minPriceDisplay = row.querySelector("[data-min-price-display]");
            const serviceData = servicesData[serviceSelect.value] || { price: 0, min_price: 0 };
            const basePrice = Number(serviceData.price || 0);
            const minPrice = Number(serviceData.min_price || 0);

            if (basePriceDisplay) {
                basePriceDisplay.value = formatMoney(basePrice);
            }

            if (minPriceDisplay) {
                minPriceDisplay.value = formatMoney(minPrice);
            }

            if (amountInput) {
                amountInput.min = minPrice.toFixed(2);

                if (shouldOverwriteAmount) {
                    amountInput.value = basePrice.toFixed(2);
                }

                if (serviceSelect.value && Number(amountInput.value || 0) < minPrice) {
                    amountInput.setCustomValidity("لا يمكن أن يقل المبلغ عن 50% من سعر الخدمة");
                } else {
                    amountInput.setCustomValidity("");
                }
            }
        }

        function bindRow(row) {
            const serviceSelect = row.querySelector("[data-service-select]");
            const amountInput = row.querySelector("[data-amount-input]");
            const removeButton = row.querySelector("[data-remove-item]");

            if (serviceSelect) {
                serviceSelect.addEventListener("change", function () {
                    updateRow(row, true);
                    updateTotals();
                });
            }

            if (amountInput) {
                amountInput.addEventListener("input", function () {
                    updateRow(row, false);
                    updateTotals();
                });
            }

            if (removeButton) {
                removeButton.addEventListener("click", function () {
                    if (cashierItems.querySelectorAll("[data-cashier-item]").length === 1) {
                        return;
                    }

                    row.remove();
                    updateRemoveButtons();
                    updateTotals();
                });
            }

            updateRow(row, false);
        }

        cashierItems.querySelectorAll("[data-cashier-item]").forEach(function (row) {
            bindRow(row);
        });

        if (addCashierItem) {
            addCashierItem.addEventListener("click", function () {
                const nextIndex = Number(cashierItems.getAttribute("data-next-index") || "0");
                const templateHtml = cashierTemplate.innerHTML.split("__INDEX__").join(String(nextIndex));
                const wrapper = document.createElement("div");
                wrapper.innerHTML = templateHtml.trim();
                const row = wrapper.firstElementChild;

                cashierItems.appendChild(row);
                cashierItems.setAttribute("data-next-index", String(nextIndex + 1));
                bindRow(row);
                updateRemoveButtons();
                updateTotals();
            });
        }

        if (barberSelect) {
            barberSelect.addEventListener("change", updateTotals);
        }

        updateRemoveButtons();
        updateTotals();
    }

    const salesInvoiceType = document.getElementById("salesInvoiceType");
    const salesEmployeeField = document.querySelector("[data-sales-employee-field]");
    const salesEmployeeSelect = document.getElementById("salesEmployeeSelect");
    const salesItems = document.getElementById("salesCashierItems");
    const salesAddItem = document.getElementById("addSalesCashierItem");
    const salesTemplate = document.getElementById("salesCashierItemTemplate");
    const salesTotalElement = document.getElementById("salesInvoiceTotal");
    const salesItemsCountElement = document.getElementById("salesItemsCount");
    const salesModeLabelElement = document.getElementById("salesInvoiceModeLabel");
    const salesDataElement = document.getElementById("salesItemsData");

    if (salesItems && salesTemplate && salesDataElement) {
        const salesData = safeJsonParse(salesDataElement, {});

        function formatSalesMoney(value) {
            return Number(value || 0).toFixed(2) + " ج";
        }

        function formatSalesQuantity(value) {
            return Number(value || 0).toFixed(2);
        }

        function syncSalesInvoiceType() {
            const isSaleInvoice = !salesInvoiceType || salesInvoiceType.value === "sale";

            if (salesModeLabelElement && salesInvoiceType) {
                salesModeLabelElement.textContent = isSaleInvoice ? "بيع" : "مرتجع";
            }

            if (salesEmployeeField) {
                salesEmployeeField.classList.toggle("sales-employee-field-hidden", !isSaleInvoice);
            }

            if (salesEmployeeSelect) {
                const hasEmployees = salesEmployeeSelect.getAttribute("data-no-employees") !== "1";
                salesEmployeeSelect.required = isSaleInvoice;
                salesEmployeeSelect.disabled = !isSaleInvoice || !hasEmployees;

                if (!isSaleInvoice) {
                    salesEmployeeSelect.value = "";
                    salesEmployeeSelect.setCustomValidity("");
                }
            }
        }

        function updateSalesTotals() {
            let total = 0;
            const rows = salesItems.querySelectorAll("[data-sales-item]");

            rows.forEach(function (row) {
                const quantityInput = row.querySelector("[data-sales-quantity-input]");
                const priceInput = row.querySelector("[data-sales-price-input]");
                total += Number(quantityInput ? quantityInput.value : 0) * Number(priceInput ? priceInput.value : 0);
            });

            if (salesTotalElement) {
                salesTotalElement.textContent = formatSalesMoney(total);
            }

            if (salesItemsCountElement) {
                salesItemsCountElement.textContent = String(rows.length);
            }
        }

        function updateSalesRemoveButtons() {
            const rows = salesItems.querySelectorAll("[data-sales-item]");
            rows.forEach(function (row) {
                const removeButton = row.querySelector("[data-remove-sales-item]");
                if (removeButton) {
                    removeButton.disabled = rows.length === 1;
                }
            });
        }

        function updateSalesRow(row, shouldOverwritePrice) {
            const itemSelect = row.querySelector("[data-sales-item-select]");
            const quantityInput = row.querySelector("[data-sales-quantity-input]");
            const priceInput = row.querySelector("[data-sales-price-input]");
            const stockDisplay = row.querySelector("[data-sales-stock-display]");
            const registeredPriceDisplay = row.querySelector("[data-sales-registered-price]");
            const minPriceDisplay = row.querySelector("[data-sales-min-price]");
            const lineTotalDisplay = row.querySelector("[data-sales-line-total]");
            const itemData = salesData[itemSelect ? itemSelect.value : ""] || {
                registered_price: 0,
                min_price: 0,
                available_quantity: null
            };
            const registeredPrice = Number(itemData.registered_price || 0);
            const minPrice = Number(itemData.min_price || 0);
            const availableQuantity = itemData.available_quantity;
            const quantity = Number(quantityInput ? quantityInput.value : 0);

            if (stockDisplay) {
                stockDisplay.value = availableQuantity === null ? "غير متابع" : formatSalesQuantity(availableQuantity);
            }

            if (registeredPriceDisplay) {
                registeredPriceDisplay.value = formatSalesMoney(registeredPrice);
            }

            if (minPriceDisplay) {
                minPriceDisplay.value = formatSalesMoney(minPrice);
            }

            if (priceInput) {
                priceInput.min = minPrice.toFixed(2);

                if (shouldOverwritePrice || priceInput.value === "") {
                    priceInput.value = registeredPrice.toFixed(2);
                }

                if (itemSelect && itemSelect.value && Number(priceInput.value || 0) < minPrice) {
                    priceInput.setCustomValidity("السعر أقل من الحد الأدنى المسموح");
                } else {
                    priceInput.setCustomValidity("");
                }
            }

            if (lineTotalDisplay) {
                lineTotalDisplay.value = formatSalesMoney(quantity * Number(priceInput ? priceInput.value : 0));
            }
        }

        function bindSalesRow(row) {
            const itemSelect = row.querySelector("[data-sales-item-select]");
            const quantityInput = row.querySelector("[data-sales-quantity-input]");
            const priceInput = row.querySelector("[data-sales-price-input]");
            const removeButton = row.querySelector("[data-remove-sales-item]");

            if (itemSelect) {
                itemSelect.addEventListener("change", function () {
                    updateSalesRow(row, true);
                    updateSalesTotals();
                });
            }

            if (quantityInput) {
                quantityInput.addEventListener("input", function () {
                    updateSalesRow(row, false);
                    updateSalesTotals();
                });
            }

            if (priceInput) {
                priceInput.addEventListener("input", function () {
                    updateSalesRow(row, false);
                    updateSalesTotals();
                });
            }

            if (removeButton) {
                removeButton.addEventListener("click", function () {
                    if (salesItems.querySelectorAll("[data-sales-item]").length === 1) {
                        return;
                    }

                    row.remove();
                    updateSalesRemoveButtons();
                    updateSalesTotals();
                });
            }

            updateSalesRow(row, false);
        }

        salesItems.querySelectorAll("[data-sales-item]").forEach(function (row) {
            bindSalesRow(row);
        });

        if (salesAddItem) {
            salesAddItem.addEventListener("click", function () {
                const nextIndex = Number(salesItems.getAttribute("data-next-index") || "0");
                const templateHtml = salesTemplate.innerHTML.split("__INDEX__").join(String(nextIndex));
                const wrapper = document.createElement("div");
                wrapper.innerHTML = templateHtml.trim();
                const row = wrapper.firstElementChild;

                salesItems.appendChild(row);
                salesItems.setAttribute("data-next-index", String(nextIndex + 1));
                bindSalesRow(row);
                updateSalesRemoveButtons();
                updateSalesTotals();
            });
        }

        if (salesInvoiceType) {
            salesInvoiceType.addEventListener("change", function () {
                syncSalesInvoiceType();
                updateSalesTotals();
            });
        }

        syncSalesInvoiceType();
        updateSalesRemoveButtons();
        updateSalesTotals();
    }
});
