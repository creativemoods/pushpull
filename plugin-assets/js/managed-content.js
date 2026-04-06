(function () {
    if (typeof window.pushpullManagedContent === 'undefined') {
        return;
    }

    const config = window.pushpullManagedContent;
    const actionControls = document.querySelectorAll('.pushpull-action-control[data-pushpull-action-title]');
    let actionPopover = null;

    const ensureActionPopover = function () {
        if (actionPopover) {
            return actionPopover;
        }

        actionPopover = document.createElement('div');
        actionPopover.className = 'pushpull-action-popover';
        actionPopover.hidden = true;
        actionPopover.innerHTML = '<strong class="pushpull-action-popover__title"></strong><p class="pushpull-action-popover__description"></p><p class="pushpull-action-popover__notice" hidden></p>';
        document.body.appendChild(actionPopover);

        return actionPopover;
    };

    const hideActionPopover = function () {
        if (!actionPopover) {
            return;
        }

        actionPopover.hidden = true;
    };

    const showActionPopover = function (control) {
        const popover = ensureActionPopover();
        const title = control.getAttribute('data-pushpull-action-title') || '';
        const description = control.getAttribute('data-pushpull-action-description') || '';
        const disabledReason = control.getAttribute('data-pushpull-action-disabled-reason') || '';
        const notice = disabledReason || control.getAttribute('data-pushpull-action-notice') || '';
        const titleNode = popover.querySelector('.pushpull-action-popover__title');
        const descriptionNode = popover.querySelector('.pushpull-action-popover__description');
        const noticeNode = popover.querySelector('.pushpull-action-popover__notice');
        const rect = control.getBoundingClientRect();

        titleNode.textContent = title;
        descriptionNode.textContent = description;
        noticeNode.textContent = notice;
        noticeNode.hidden = notice === '';
        popover.hidden = false;

        const top = window.scrollY + rect.bottom + 10;
        const left = window.scrollX + rect.left;
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    };

    actionControls.forEach(function (control) {
        control.addEventListener('mouseenter', function () {
            showActionPopover(control);
        });
        control.addEventListener('focusin', function () {
            showActionPopover(control);
        });
        control.addEventListener('mouseleave', hideActionPopover);
        control.addEventListener('focusout', function (event) {
            if (!control.contains(event.relatedTarget)) {
                hideActionPopover();
            }
        });
    });

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
    let lastStableMessage = config.strings.working;

    const openModal = function (label, currentMessage) {
        title.textContent = label || config.strings.working;
        lastStableMessage = currentMessage || config.strings.working;
        message.textContent = lastStableMessage;
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

            if (data.message) {
                lastStableMessage = data.message;
            }

            message.textContent = lastStableMessage;
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
                if (data.message) {
                    lastStableMessage = data.message;
                }

                message.textContent = lastStableMessage;
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
