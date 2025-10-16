// assets/js/ip-form.js
// Динамическая валидация согласованности данных для формы IP-адресов

function validateFormConsistency() {
    const deviceId = document.getElementById('device_id').value;
    const status = document.getElementById('status').value;
    const statusError = document.getElementById('status-error');
    
    // Убираем предыдущее сообщение об ошибке
    if (statusError) {
        statusError.remove();
    }
    
    let isValid = true;
    let errorMessage = '';
    
    if (deviceId && status === 'free') {
        errorMessage = 'IP-адрес с привязанным устройством не может быть свободным';
        isValid = false;
    }
    
    if (!deviceId && status === 'active') {
        errorMessage = 'Активный IP-адрес должен быть привязан к устройству';
        isValid = false;
    }
    
    if (deviceId && status === 'reserved') {
        errorMessage = 'Зарезервированный IP-адрес не должен быть привязан к устройству';
        isValid = false;
    }
    
    // Показываем сообщение об ошибке
    if (!isValid) {
        const errorDiv = document.createElement('div');
        errorDiv.id = 'status-error';
        errorDiv.className = 'text-danger mt-1';
        errorDiv.textContent = errorMessage;
        document.getElementById('status').parentNode.appendChild(errorDiv);
    }
    
    return isValid;
}

function updateAvailableStatuses() {
    const deviceId = document.getElementById('device_id').value;
    const statusSelect = document.getElementById('status');
    const currentStatus = statusSelect.value;
    
    // Сбрасываем все опции
    Array.from(statusSelect.options).forEach(option => {
        option.disabled = false;
    });
    
    if (deviceId) {
        // Если выбрано устройство - отключаем "Свободен" и "Зарезервирован"
        statusSelect.querySelector('option[value="free"]').disabled = true;
        statusSelect.querySelector('option[value="reserved"]').disabled = true;
        
        // Если текущий статус стал недоступен - меняем на "Активен"
        if (currentStatus === 'free' || currentStatus === 'reserved') {
            statusSelect.value = 'active';
        }
    } else {
        // Если устройство не выбрано - отключаем "Активен"
        statusSelect.querySelector('option[value="active"]').disabled = true;
        
        // Если текущий статус стал недоступен - меняем на "Свободен"
        if (currentStatus === 'active') {
            statusSelect.value = 'free';
        }
    }
    
    // Запускаем валидацию
    validateFormConsistency();
}

// Инициализация событий
function initIpForm() {
    const deviceSelect = document.getElementById('device_id');
    const statusSelect = document.getElementById('status');
    const form = document.getElementById('ip-form');
    
    if (deviceSelect && statusSelect && form) {
        deviceSelect.addEventListener('change', updateAvailableStatuses);
        statusSelect.addEventListener('change', validateFormConsistency);
        
        form.addEventListener('submit', function(e) {
            if (!validateFormConsistency()) {
                e.preventDefault();
                alert('Исправьте несоответствия в форме перед отправкой');
            }
        });
        
        // Инициализация при загрузке
        updateAvailableStatuses();
    }
}

// Запуск при загрузке DOM
document.addEventListener('DOMContentLoaded', initIpForm);