document.body.addEventListener('click', (e) => {
    let button = e.target;

    if (button.classList.contains('mltTranslate')) {
        e.preventDefault();
        mltTranslate(button);
    } else if (button.classList.contains('mltAddById')) {
        e.preventDefault();
        mltAddById(button);
    } else if (button.classList.contains('mltRemoveId')) {
        e.preventDefault();
        mltRemoveId(button);
    }
});

function mltTranslate(button) {
    let parent = button.parentElement,
        addByIdWrapper = parent.nextSibling,
        addById,
        addByIdInput;

    if (addByIdWrapper) {
        addById = addByIdWrapper.querySelector('.mltAddById');
        addByIdInput = addByIdWrapper.querySelector('input');
        addById.setAttribute('disabled', 'disabled');
        addByIdInput.setAttribute('disabled', 'disabled');
    }

    button.setAttribute('disabled', 'disabled');
    button.nextSibling.classList.add('is-active');

    fetch(mlt.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `action=mlt_generate&nonce=${mlt.nonce}&post_id=${button.dataset.post_id}&blog_id=${button.dataset.blog_id}&type=${button.dataset.type}`
    })
        .then(response => {
            if (response.ok) {
                return response.json();
            } else {
                throw new Error('Request failed.');
            }
        })
        .then(({ success, data }) => {
            console.log(data);
            if (success) {
                parent.parentElement.innerHTML = data;
                if (addByIdWrapper) {
                    addByIdWrapper.remove();
                }
            } else {
                throw new Error(data);
            }
        })
        .catch(error => {
            console.error(error);
        });
}

function mltAddById(button) {
    let parent = button.parentElement;
    let translateWrapper = parent.previousSibling;
    let translateButton = translateWrapper.querySelector('.mltTranslate');

    button.setAttribute('disabled', 'disabled');
    button.previousSibling.setAttribute('disabled', 'disabled');
    translateButton.setAttribute('disabled', 'disabled');
    button.nextSibling.classList.add('is-active');

    fetch(mlt.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `action=mlt_add_post_by_id&nonce=${mlt.nonce}&new_post_id=${button.previousSibling.value}&post_id=${button.dataset.post_id}&blog_id=${button.dataset.blog_id}&type=${button.dataset.type}`
    })
        .then(response => {
            if (response.ok) {
                return response.json();
            } else {
                throw new Error('Request failed.');
            }
        })
        .then(({ success, data }) => {
            console.log(data);
            if (success) {
                parent.parentElement.innerHTML = data;
            } else {
                button.removeAttribute('disabled');
                button.previousSibling.removeAttribute('disabled');
                translateButton.removeAttribute('disabled');
                button.nextSibling.classList.remove('is-active');
                throw new Error(data);
            }
        })
        .catch(error => {
            console.error(error);
        });
}

function mltRemoveId(button) {
    if (!window.confirm('Are you sure?')) {
        return;
    }

    let parent = button.parentElement;
    let editButton = button.previousSibling;

    button.setAttribute('disabled', 'disabled');
    editButton.classList.add('button-disabled');
    editButton.style.pointerEvents = 'none';
    button.nextSibling.classList.add('is-active');

    fetch(mlt.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `action=mlt_remove_id&nonce=${mlt.nonce}&from_post_id=${button.dataset.from_post_id}&post_id=${button.dataset.post_id}&blog_id=${button.dataset.blog_id}&type=${button.dataset.type}`
    })
        .then(response => {
            if (response.ok) {
                return response.json();
            } else {
                throw new Error('Request failed.');
            }
        })
        .then(({ success, data }) => {
            console.log(data);
            if (success) {
                parent.innerHTML = data;
            } else {
                throw new Error(data);
            }
        })
        .catch(error => {
            console.error(error);
        });
}