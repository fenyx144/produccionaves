<?php
require_once __DIR__ . '/../lib/sip_asset_version.php';
sip_send_app_no_cache_headers();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso — Producción Aves</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(sip_asset_url('../../assets/css/sip-overlays-no-blur.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-shapes {
            background-image: radial-gradient(circle at 15% 50%, rgba(255, 255, 255, 0.08), transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(255, 255, 255, 0.08), transparent 25%);
        }
        @keyframes scaleIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .animate-scale-in { animation: scaleIn 0.2s ease-out; }
    </style>
</head>

<body class="bg-gray-50 font-sans antialiased text-gray-900">
    <div class="min-h-screen flex w-full">
        <div class="hidden lg:flex lg:w-3/5 bg-gradient-to-br from-[#003087] to-blue-600 items-center justify-center relative overflow-hidden bg-shapes">
            <div class="relative z-10 text-center text-white p-12 flex flex-col items-center">
                <div class="p-8 rounded-3xl mb-10 shadow-2xl bg-[#011F49]">
                    <i class="fas fa-feather text-6xl text-white/90" aria-hidden="true"></i>
                </div>
                <h2 class="text-4xl font-extrabold mb-4 tracking-tight">Producción Aves</h2>
                <p class="text-blue-100 text-lg max-w-md font-light">Gestión de mortalidad avícola</p>
                <p class="text-blue-200/90 text-base max-w-md font-medium mt-2 tracking-wide">Granja Rinconada del Sur</p>
            </div>
        </div>

        <div class="w-full lg:w-1/2 flex items-center justify-center p-6 sm:p-12 relative bg-white">
            <div class="absolute top-8 left-1/2 transform -translate-x-1/2 lg:hidden">
                <div class="bg-white p-3 rounded-2xl shadow-lg ring-1 ring-gray-200/90">
                    <i class="fas fa-feather text-blue-700 text-3xl" aria-hidden="true"></i>
                </div>
            </div>

            <div class="w-full max-w-md space-y-8 pt-16 lg:pt-0">
                <div class="text-center lg:text-left">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">¡Bienvenido de nuevo!</h1>
                    <p class="text-gray-500">Por favor, ingresa tus credenciales para continuar.</p>
                </div>

                <form id="loginForm" method="POST" class="space-y-6 mt-8">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" for="usuario">Usuario</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-user text-gray-400 group-focus-within:text-blue-600 transition-colors"></i>
                            </div>
                            <input type="text" id="usuario" name="usuario" required autocomplete="username"
                                class="block w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200"
                                placeholder="Ingresa tu código de usuario">
                        </div>
                    </div>

                    <input type="hidden" name="gps" id="gpsInput" value="">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" for="clave">Contraseña</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400 group-focus-within:text-blue-600 transition-colors"></i>
                            </div>
                            <input type="password" id="clave" name="clave" required autocomplete="current-password"
                                class="block w-full pl-11 pr-12 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200"
                                placeholder="••••••••">
                            <button type="button" id="togglePassword" tabindex="-1"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-blue-600 focus:outline-none transition-colors"
                                aria-label="Mostrar u ocultar contraseña">
                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div id="alertContainer" class="hidden items-start gap-2 p-3 text-sm text-red-600 bg-red-50 rounded-lg border border-red-100" role="alert">
                        <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                        <span id="alertMessage"></span>
                    </div>

                    <button type="submit" id="btnSubmit"
                        class="w-full flex justify-center items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white py-3.5 px-4 rounded-xl font-semibold shadow-lg shadow-blue-600/30 hover:shadow-blue-600/40 transform hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-blue-500/50">
                        <span>Ingresar al Sistema</span>
                        <i class="fas fa-arrow-right text-sm"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="loading" class="fixed inset-0 bg-gray-900/40 hidden items-center justify-center z-50">
        <div class="bg-white px-8 py-6 rounded-2xl shadow-2xl flex flex-col items-center gap-4 animate-scale-in">
            <div class="w-12 h-12 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="text-gray-700 font-medium">Validando credenciales...</p>
        </div>
    </div>

    <script>
        (function () {
            function wirePasswordToggle(btn, input, icon) {
                if (!btn || !input || !icon) return;
                btn.addEventListener('click', function () {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                });
            }

            wirePasswordToggle(
                document.getElementById('togglePassword'),
                document.getElementById('clave'),
                document.getElementById('togglePasswordIcon')
            );

            function mostrarAlerta(html) {
                var box = document.getElementById('alertContainer');
                var msg = document.getElementById('alertMessage');
                if (!box || !msg) return;
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var inner = tmp.querySelector('.alert-danger') || tmp.firstElementChild || tmp;
                msg.textContent = inner.textContent.trim() || html.replace(/<[^>]+>/g, '').trim();
                box.classList.remove('hidden');
                box.classList.add('flex');
            }

            function ocultarAlerta() {
                var box = document.getElementById('alertContainer');
                if (box) {
                    box.classList.add('hidden');
                    box.classList.remove('flex');
                }
            }

            function setLoading(on) {
                var el = document.getElementById('loading');
                if (!el) return;
                el.classList.toggle('hidden', !on);
                el.classList.toggle('flex', on);
            }

            document.getElementById('loginForm').addEventListener('submit', async function (e) {
                e.preventDefault();
                ocultarAlerta();

                var usuario = document.getElementById('usuario').value.trim();
                var clave = document.getElementById('clave').value.trim();
                var gps = document.getElementById('gpsInput').value || 'no-disponible';

                if (!usuario || !clave) {
                    mostrarAlerta('<div class="alert alert-danger">Ingrese su usuario y contraseña</div>');
                    return;
                }

                setLoading(true);
                var formData = new FormData();
                formData.append('usuario', usuario);
                formData.append('clave', clave);
                formData.append('gps', gps);

                try {
                    var response = await fetch('login_handler.php', { method: 'POST', body: formData });
                    var result = await response.json();
                    if (result.success) {
                        try {
                            localStorage.removeItem('ix2-sidebar-module');
                            sessionStorage.removeItem('ix2-sidebar-module');
                        } catch (eClearNav) {}
                        window.location.href = '../../index.php';
                    } else {
                        mostrarAlerta('<div class="alert alert-danger">' + (result.message || 'Error') + '</div>');
                    }
                } catch (err) {
                    mostrarAlerta('<div class="alert alert-danger">Error de conexión. Intente nuevamente.</div>');
                } finally {
                    setLoading(false);
                }
            });

            async function setGpsHiddenInput() {
                var gpsInput = document.getElementById('gpsInput');
                if (!gpsInput) return;
                if (gpsInput.value && gpsInput.value.trim() !== '') return;
                if (!navigator.geolocation) {
                    gpsInput.value = 'no-disponible';
                    return;
                }
                gpsInput.value = await new Promise(function (resolve) {
                    navigator.geolocation.getCurrentPosition(
                        function (pos) {
                            var lat = pos.coords.latitude;
                            var lon = pos.coords.longitude;
                            var acc = pos.coords.accuracy;
                            resolve(lat + ',' + lon + ' (±' + Math.round(acc) + 'm)');
                        },
                        function (err) {
                            if (err.code === err.PERMISSION_DENIED) resolve('no-permitido');
                            else resolve('no-disponible');
                        },
                        { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 }
                    );
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                setGpsHiddenInput();
                var loginForm = document.querySelector('form');
                if (loginForm) {
                    loginForm.addEventListener('submit', function () {
                        setGpsHiddenInput();
                    });
                }
            });
        })();
    </script>
</body>
</html>
