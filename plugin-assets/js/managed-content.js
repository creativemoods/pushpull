(function () {
    if (typeof window.pushpullManagedContent === 'undefined') {
        return;
    }

    const config = window.pushpullManagedContent;
    const modal = document.querySelector('.pushpull-async-modal');

    if (!modal) {
        return;
    }

    const title = modal.querySelector('#pushpull-async-modal-title');
    const message = modal.querySelector('.pushpull-async-modal__message');
    const closeButton = modal.querySelector('.pushpull-async-modal__close');
    const progressWrap = modal.querySelector('.pushpull-async-modal__progress');
    const progressBar = modal.querySelector('.pushpull-async-modal__progress-bar');
    const progressFill = modal.querySelector('.pushpull-async-modal__progress-fill');
    const progressLabel = modal.querySelector('.pushpull-async-modal__progress-label');

    const openModal = function (label, currentMessage) {
        title.textContent = label || config.strings.working;
        message.textContent = currentMessage || config.strings.working;
        closeButton.hidden = true;
        updateProgress({ mode: 'indeterminate', current: 0, total: 0 });
        modal.hidden = false;
        document.body.classList.add('pushpull-modal-open');
    };

    const closeModal = function () {
        modal.hidden = true;
        document.body.classList.remove('pushpull-modal-open');
    };

    closeButton.addEventListener('click', closeModal);

    const updateProgress = function (progress) {
        const data = progress || { mode: 'indeterminate', current: 0, total: 0 };
        progressWrap.hidden = false;

        if (data.mode === 'determinate' && data.total > 0) {
            const percent = Math.max(0, Math.min(100, Math.round((data.current / data.total) * 100)));
            progressBar.classList.remove('is-indeterminate');
            progressBar.setAttribute('aria-valuenow', String(percent));
            progressFill.style.width = percent + '%';
            progressLabel.textContent = config.strings.progressPercent.replace('%d', String(percent));
            return;
        }

        progressBar.classList.add('is-indeterminate');
        progressBar.setAttribute('aria-valuenow', '0');
        progressFill.style.width = '100%';
        progressLabel.textContent = '';
    };

    const postJson = async function (action, payload) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', config.ajaxNonce);

        Object.keys(payload).forEach(function (key) {
            body.set(key, payload[key]);
        });

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        });
        const rawBody = await response.text();
        let data = null;

        try {
            data = JSON.parse(rawBody);
        } catch (error) {
            const htmlMessage = rawBody
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            throw new Error(htmlMessage || config.strings.failed);
        }

        if (!response.ok || !data.success) {
            throw new Error((data.data && data.data.message) || config.strings.failed);
        }

        return data.data;
    };

    const continueOperation = async function (operationId, label, retryCount) {
        const currentRetryCount = retryCount || 0;

        try {
            const data = await postJson('pushpull_continue_branch_action', {
                operation_id: String(operationId)
            });

            message.textContent = data.message || config.strings.working;
            updateProgress(data.progress);

            if (!data.done) {
                window.setTimeout(function () {
                    continueOperation(operationId, label, 0).catch(handleFailure);
                }, 50);
                return;
            }

            if (data.redirectUrl) {
                window.location.assign(data.redirectUrl);
                return;
            }

            title.textContent = label;
            closeButton.hidden = false;
        } catch (error) {
            if (currentRetryCount < 5) {
                title.textContent = label;
                message.textContent = config.strings.checkingStatus || config.strings.working;

                window.setTimeout(function () {
                    continueOperation(operationId, label, currentRetryCount + 1).catch(handleFailure);
                }, 2000);
                return;
            }

            throw error;
        }
    };

    const handleFailure = function (error) {
        title.textContent = config.strings.failed;
        message.textContent = error.message || config.strings.failed;
        closeButton.hidden = false;
    };

    document.querySelectorAll('.pushpull-async-branch-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const managedSetInput = form.querySelector('input[name="managed_set"]');
            const operationType = form.getAttribute('data-pushpull-async-operation') || '';
            const label = form.getAttribute('data-pushpull-async-label') || config.strings.working;
            const confirmationMessage = form.getAttribute('data-pushpull-confirm');

            if (confirmationMessage && !window.confirm(confirmationMessage)) {
                return;
            }

            openModal(label, config.strings.working);

            postJson('pushpull_start_branch_action', {
                operation_type: operationType,
                managed_set: managedSetInput ? managedSetInput.value : ''
            }).then(function (data) {
                message.textContent = data.message || config.strings.working;
                updateProgress(data.progress);

                if (data.done && data.redirectUrl) {
                    window.location.assign(data.redirectUrl);
                    return null;
                }

                return continueOperation(data.operationId, label, 0);
            }).catch(handleFailure);
        });
    });
}());
