

document.addEventListener('DOMContentLoaded', () => {

    checkPersistedSession();
});

/**

 * @param {'login' | 'register'} tab 
 */
function switchTab(tab) {
    const loginView = document.getElementById('login-view');
    const registerView = document.getElementById('register-view');
    const tabLogin = document.getElementById('tab-login');
    const tabRegister = document.getElementById('tab-register');


    clearFormErrors();

    if (tab === 'login') {
        registerView.classList.remove('active');
        tabRegister.classList.remove('active');

        setTimeout(() => {
            loginView.classList.add('active');
            tabLogin.classList.add('active');
        }, 150);
    } else {
        loginView.classList.remove('active');
        tabLogin.classList.remove('active');

        setTimeout(() => {
            registerView.classList.add('active');
            tabRegister.classList.add('active');
        }, 150);
    }
}

/**

 * @param {string} inputId 
 * @param {HTMLButtonElement} btn 
 */
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

/**

 * @param {string} password 
 */
function evaluatePasswordStrength(password) {
    const bar1 = document.getElementById('bar-1');
    const bar2 = document.getElementById('bar-2');
    const bar3 = document.getElementById('bar-3');
    const bar4 = document.getElementById('bar-4');
    const strengthText = document.getElementById('strength-text');

    const bars = [bar1, bar2, bar3, bar4];


    bars.forEach(bar => bar.style.backgroundColor = 'rgba(25, 41, 24, 0.1)');

    if (!password) {
        strengthText.textContent = 'Escribe una contraseña';
        strengthText.style.color = 'var(--color-text-muted)';
        return 0;
    }

    let score = 0;
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    if (score === 1) {
        bar1.style.backgroundColor = '#d32f2f';
        strengthText.textContent = 'Contraseña débil (Añade números o mayúsculas)';
        strengthText.style.color = '#d32f2f';
    } else if (score === 2) {
        bar1.style.backgroundColor = '#f57c00';
        bar2.style.backgroundColor = '#f57c00';
        strengthText.textContent = 'Contraseña media (Añade símbolos)';
        strengthText.style.color = '#f57c00';
    } else if (score === 3) {
        bar1.style.backgroundColor = '#fbc02d';
        bar2.style.backgroundColor = '#fbc02d';
        bar3.style.backgroundColor = '#fbc02d';
        strengthText.textContent = 'Contraseña buena';
        strengthText.style.color = '#c69214';
    } else if (score === 4) {
        bars.forEach(bar => bar.style.backgroundColor = '#2e7d32');
        strengthText.textContent = 'Contraseña excelente y muy segura';
        strengthText.style.color = '#2e7d32';
    }

    return score;
}

/**

 * @param {Event} e 
 */
async function handleLogin(e) {
    e.preventDefault();
    clearFormErrors();

    const emailInput = document.getElementById('login-email');
    const passwordInput = document.getElementById('login-password');
    const rememberMe = document.getElementById('remember-me').checked;
    const submitBtn = document.getElementById('btn-login-submit');

    let isValid = true;


    if (!emailInput.value || !validateEmail(emailInput.value)) {
        showFieldError('login-email-error', 'Por favor ingresa un correo electrónico válido.');
        isValid = false;
    }


    if (!passwordInput.value) {
        showFieldError('login-password-error', 'Ingresa tu contraseña.');
        isValid = false;
    }

    if (!isValid) return;


    submitBtn.classList.add('loading');
    submitBtn.disabled = true;

try {
    const response = await fetch('login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: emailInput.value.trim(),
            password: passwordInput.value
        })
    });

        const data = await response.json();

        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;

            if (data.success) {
            showToast(data.message || '¡Sesión iniciada con éxito!', 'success');
            if (rememberMe) {
                localStorage.setItem('nucleo_user', data.user.email);
            } else {
                localStorage.setItem('nucleo_user', data.user.email);
            }

            setTimeout(() => {
                window.location.href = 'dashboard.html';
            }, 1000);
        } else {
            if (data.field) {
                showFieldError(`login-${data.field}-error`, data.message);
            }
            showToast(data.message || 'Credenciales incorrectas.', 'error');
        }
    } catch (err) {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        console.error('Login error:', err);
    }
}

/**

 * @param {Event} e 
 */
async function handleRegister(e) {
    e.preventDefault();
    clearFormErrors();

    const fullName = document.getElementById('reg-fullname');
    const company = document.getElementById('reg-company');
    const email = document.getElementById('reg-email');
    const password = document.getElementById('reg-password');
    const confirmPassword = document.getElementById('reg-confirm-password');
    const terms = document.getElementById('reg-terms');
    const submitBtn = document.getElementById('btn-register-submit');

    let isValid = true;

    if (!fullName.value.trim()) {
        showFieldError('reg-fullname-error', 'Ingresa tu nombre completo.');
        isValid = false;
    }

    if (!company.value.trim()) {
        showFieldError('reg-company-error', 'Ingresa el nombre de tu empresa.');
        isValid = false;
    }

    if (!email.value || !validateEmail(email.value)) {
        showFieldError('reg-email-error', 'Ingresa un correo corporativo válido.');
        isValid = false;
    }

    if (password.value.length < 8) {
        showFieldError('reg-password-error', 'La contraseña debe tener al menos 8 caracteres.');
        isValid = false;
    }

    if (password.value !== confirmPassword.value) {
        showFieldError('reg-confirm-password-error', 'Las contraseñas no coinciden.');
        isValid = false;
    }

    if (!terms.checked) {
        showFieldError('reg-terms-error', 'Debes aceptar los Términos de Servicio.');
        isValid = false;
    }

    if (!isValid) return;


    submitBtn.classList.add('loading');
    submitBtn.disabled = true;

    try {
        const response = await fetch('register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fullname: fullName.value.trim(),
                company: company.value.trim(),
                email: email.value.trim(),
                password: password.value
            })
        });

        const data = await response.json();

        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;

if (data.success) {
            showToast(data.message || 'Cuenta registrada. Por favor inicia sesión.', 'success');
            
            document.getElementById('register-form').reset();

            setTimeout(() => {
                switchTab('login');
            }, 1500);
        } else {
            if (data.field) {
                showFieldError(`reg-${data.field}-error`, data.message);
            }
            showToast(data.message || 'No se pudo crear la cuenta.', 'error');
        }
    } catch (err) {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        console.error('Register error:', err);
    }
}

/**

 * @param {string} provider 
 */
function handleSocialAuth(provider) {
    showToast(`Conectando con ${provider}...`, 'info');

    setTimeout(() => {
        const mockEmail = `usuario.${provider.toLowerCase()}@nucleocrm.io`;
        localStorage.setItem('nucleo_user', mockEmail);
        localStorage.setItem('nucleo_fullname', 'Usuario ' + provider);
        showToast(`¡Autenticado con éxito usando ${provider}!`, 'success');
        setTimeout(() => {
            window.location.href = 'dashboard.html';
        }, 600);
    }, 1000);
}

/**
 */
function openForgotModal(e) {
    e.preventDefault();
    const modal = document.getElementById('forgot-modal');
    modal.classList.add('active');
}

function closeForgotModal() {
    const modal = document.getElementById('forgot-modal');
    modal.classList.remove('active');
}

function handleForgotSubmit(e) {
    e.preventDefault();
    const email = document.getElementById('forgot-email').value;

    if (!validateEmail(email)) {
        showToast('Ingresa un correo válido.', 'error');
        return;
    }

    closeForgotModal();
    showToast(`Hemos enviado un enlace de recuperación a ${email}`, 'success');
}

/**
 * @param {string} email 
 * @param {string} [name] 
 */
function showDashboard(email, name = '') {
    localStorage.setItem('nucleo_user', email);
    if (name) localStorage.setItem('nucleo_fullname', name);
    window.location.href = 'dashboard.html';
}

/**
 */
async function handleLogout() {
    const dashboard = document.getElementById('dashboard-preview');
    if (dashboard) dashboard.classList.remove('active');
    localStorage.removeItem('nucleo_user');

    try {
        await fetch('logout.php');
    } catch (err) {
        console.log('Logout API call exception ignored');
    }

    showToast('Has cerrado sesión correctamente.', 'info');
}

async function checkPersistedSession() {
    try {
        const res = await fetch('check_session.php');
        const data = await res.json();
        if (data.authenticated && data.user) {
            localStorage.setItem('nucleo_user', data.user.email || '');
            localStorage.setItem('nucleo_fullname', data.user.fullname || '');
            window.location.href = 'dashboard.html';
        }
    } catch (err) {
        // Silencio en index
    }
}

/**
 */
function validateEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function showFieldError(elementId, message) {
    const el = document.getElementById(elementId);
    if (el) el.textContent = message;
}

function clearFormErrors() {
    document.querySelectorAll('.error-msg').forEach(el => el.textContent = '');
}

/**
 * @param {string} message 
 * @param {'success' | 'error' | 'info'} type 
 */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let iconClass = 'fa-circle-info';
    if (type === 'success') iconClass = 'fa-circle-check';
    if (type === 'error') iconClass = 'fa-circle-xmark';

    toast.innerHTML = `<i class="fa-solid ${iconClass}"></i> <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(40px)';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}
