var _currentLang = '<?php echo $currentLang; ?>';
        var LANG = <?php
            $langArr = lang_load();
            echo json_encode([
                'msg_restricted_reason' => $langArr['msg_restricted_reason'] ?? 'Reason: %s',
                'msg_pow_working' => $langArr['msg_pow_working'] ?? 'Logging in...',
                'msg_pow_registering' => $langArr['msg_pow_registering'] ?? 'Registering...',
            ], JSON_UNESCAPED_UNICODE);
        ?>;
        var _restrictedUser = null;
        var _restrictedPass = null;

        function t(key, fallback) {
            return typeof LANG !== 'undefined' && LANG && LANG[key] ? LANG[key] : (fallback !== undefined ? fallback : key);
        }

        function switchLang(lang) {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
            var loginOpen = (tab === 'login');
            document.querySelectorAll('.tab-btn')[loginOpen ? 0 : 1].classList.add('active');
            document.getElementById('loginWrap').classList.toggle('open', loginOpen);
            document.getElementById('registerWrap').classList.toggle('open', !loginOpen);
            hideError();
        }

        function showError(msg) {
            var el = document.getElementById('errorMsg');
            el.textContent = msg;
            el.classList.add('show');
        }

        function hideError() {
            var el = document.getElementById('errorMsg');
            el.classList.remove('show');
            el.textContent = '';
        }

        function renderRestricted(displayName, reason) {
            document.getElementById('langSelector').style.display = 'none';
            document.getElementById('normalAuth').style.display = 'none';
            document.getElementById('backLink').style.display = 'none';
            document.getElementById('restrictedPanel').classList.add('active');
            document.getElementById('rstrGreeting').textContent = displayName + ',';
            document.getElementById('rstrReason').textContent = t('msg_restricted_reason', 'Reason: %s').replace('%s', reason || '-');
        }

        function showRestricted(data) {
            _restrictedUser = document.getElementById('loginUsername').value.trim();
            _restrictedPass = document.getElementById('loginPassword').value;
            // If the user's preferred language differs from the current page
            // language, save credentials and reload with their language so the
            // restricted screen renders in their own i18n.
            if (data.preferred_language && data.preferred_language !== _currentLang) {
                try {
                    sessionStorage.setItem('rstr_user', _restrictedUser);
                    sessionStorage.setItem('rstr_pass', _restrictedPass);
                    sessionStorage.setItem('rstr_name', data.display_name || _restrictedUser);
                    sessionStorage.setItem('rstr_reason', data.reason || '');
                } catch (e) {}
                var url = new URL(window.location.href);
                url.searchParams.set('lang', data.preferred_language);
                url.searchParams.set('restricted', '1');
                window.location.href = url.toString();
                return;
            }
            renderRestricted(data.display_name || _restrictedUser, data.reason || '');
        }

        // Auto-restore the restricted screen after language redirect
        (function() {
            var params = new URLSearchParams(window.location.search);
            if (params.get('restricted') === '1') {
                try {
                    var u = sessionStorage.getItem('rstr_user');
                    var p = sessionStorage.getItem('rstr_pass');
                    if (u && p) {
                        _restrictedUser = u;
                        _restrictedPass = p;
                        var name = sessionStorage.getItem('rstr_name') || u;
                        var reason = sessionStorage.getItem('rstr_reason') || '';
                        renderRestricted(name, reason);
                        sessionStorage.removeItem('rstr_user');
                        sessionStorage.removeItem('rstr_pass');
                        sessionStorage.removeItem('rstr_name');
                        sessionStorage.removeItem('rstr_reason');
                    }
                } catch (e) {}
            }
        })();

        async function doContinueLogin() {
            if (!_restrictedUser || !_restrictedPass) {
                window.location.reload();
                return;
            }
            var formData = new URLSearchParams();
            formData.append('action', 'login');
            formData.append('username', _restrictedUser);
            formData.append('password', _restrictedPass);
            formData.append('confirm', '1');
            try {
                var resp = await fetch('../../api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                var data = await resp.json();
                if (data.success) {
                    window.location.href = 'chat.php';
                } else {
                    window.location.reload();
                }
            } catch (err) {
                window.location.reload();
            }
        }

        function doLogout() {
            fetch('../../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=logout'
            }).catch(function() {}).finally(function() {
                window.location.href = '../../index.php';
            });
        }

        var _powLabel = t('msg_pow_working', 'Logging in...');
        var _powRegisterLabel = t('msg_pow_registering', 'Registering...');

        function setButtonWorking(btn, label) {
            btn.classList.add('pow-working');
            btn.textContent = label;
        }

        function resetButton(btn, label) {
            btn.classList.remove('pow-working');
            btn.textContent = label;
        }

        async function fetchPowChallenge() {
            try {
                var resp = await fetch('../../api/auth.php?action=challenge');
                var data = await resp.json();
                if (data.success && data.challenge && data.target) return data;
            } catch (e) {}
            return null;
        }

        // Fetch challenge → solve (button shows "Logging in... (n kH/s)") → POST.
        // Auto-retries once when the server reports an expired/failed challenge.
        async function submitWithPow(btn, action, appendFields, label) {
            if (!label) label = _powLabel;
            var retried = false;
            while (true) {
                var pow = await fetchPowChallenge();
                if (!pow) return { success: false, error: 'Something went wrong.' };
                var solved = await ChatAppPow.solve(pow.challenge, pow.target, function (kHps) {
                    btn.textContent = label + ' (' + Math.round(kHps) + ' kH/s)';
                });
                if (!solved) return { success: false, error: 'Something went wrong.' };
                var fd = new URLSearchParams();
                fd.append('action', action);
                fd.append('pow_challenge', pow.challenge);
                fd.append('pow_nonce', solved.nonce);
                appendFields(fd);
                var data;
                try {
                    var resp = await fetch('../../api/auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: fd.toString()
                    });
                    data = await resp.json();
                } catch (err) {
                    return { success: false, error: 'Something went wrong.' };
                }
                if (data.error === 'pow_challenge_failed' && !retried) {
                    retried = true;
                    continue; // refetch + re-solve once
                }
                return data;
            }
        }

        function powErrorText(data) {
            if (data.error === 'pow_challenge_failed') return 'Please try again.';
            return data.error || 'Something went wrong.';
        }

        async function handleLogin(e) {
            e.preventDefault();
            hideError();

            var username = document.getElementById('loginUsername').value.trim();
            var password = document.getElementById('loginPassword').value;
            var btn = document.getElementById('loginBtn');
            var origLabel = btn.textContent;

            setButtonWorking(btn, _powLabel);
            try {
                var data = await submitWithPow(btn, 'login', function (fd) {
                    fd.append('username', username);
                    fd.append('password', password);
                });
                if (data.success) {
                    window.location.href = 'chat.php';
                } else if (data.restricted) {
                    resetButton(btn, origLabel);
                    showRestricted(data);
                } else {
                    resetButton(btn, origLabel);
                    showError(powErrorText(data));
                }
            } catch (err) {
                resetButton(btn, origLabel);
                showError('Something went wrong.');
            }
        }

        async function handleRegister(e) {
            e.preventDefault();
            hideError();

            var username = document.getElementById('regUsername').value.trim();
            var password = document.getElementById('regPassword').value;
            var password2 = document.getElementById('regPassword2').value;

            if (password !== password2) {
                showError('<?php echo t('msg_login_password_mismatch'); ?>');
                return;
            }

            var btn = document.getElementById('registerBtn');
            var origLabel = btn.textContent;

            setButtonWorking(btn, _powRegisterLabel);
            try {
                var data = await submitWithPow(btn, 'register', function (fd) {
                    fd.append('username', username);
                    fd.append('password', password);
                    fd.append('language', document.getElementById('regLanguage').value);
                }, _powRegisterLabel);
                if (data.success) {
                    window.location.href = 'chat.php';
                } else {
                    resetButton(btn, origLabel);
                    showError(powErrorText(data));
                }
            } catch (err) {
                resetButton(btn, origLabel);
                showError('Something went wrong.');
            }
        }