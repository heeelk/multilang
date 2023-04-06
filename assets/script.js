const mltTranslate = document.querySelectorAll('.mltTranslate');
const mltAddByIdButton = document.querySelectorAll('.mltAddById');

if (mltTranslate.length) {
    mltTranslate.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();

            let parent = button.parentElement,
                addByIdWrapper = parent.nextSiblingm,
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
                body: `action=mlt_generate&nonce=${mlt.nonce}&post_id=${button.dataset.post_id}&blog_id=${button.dataset.blog_id}`
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
        });
    });
}

if (mltAddByIdButton.length) {
    mltAddByIdButton.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();

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
                body: `action=mlt_add_post_by_id&nonce=${mlt.nonce}&new_post_id=${button.previousSibling.value}&post_id=${button.dataset.post_id}&blog_id=${button.dataset.blog_id}`
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
                        parent.remove();
                        translateWrapper.innerHTML = data;
                    } else {
                        throw new Error(data);
                    }
                })
                .catch(error => {
                    console.error(error);
                });
        });
    });
}