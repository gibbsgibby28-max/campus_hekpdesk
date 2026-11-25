function initializeApp() {
    var menuButton = document.querySelector('.mobile-menu');
    var sidebar = document.querySelector('.sidebar');

    if (menuButton && sidebar) {
        menuButton.addEventListener('click', function () {
            var open = sidebar.classList.toggle('is-open');
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (sidebar.classList.contains('is-open') && !sidebar.contains(event.target) && !menuButton.contains(event.target)) {
                sidebar.classList.remove('is-open');
                menuButton.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.querySelectorAll('.alert-close').forEach(function (button) {
        button.addEventListener('click', function () {
            var alert = button.closest('.alert');
            if (alert) alert.remove();
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) return;
            var showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.textContent = showPassword ? 'Hide' : 'Show';
            button.setAttribute('aria-label', (showPassword ? 'Hide' : 'Show') + ' password');
            button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
        });
    });

    var fileInput = document.querySelector('.file-field input[type="file"]');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var label = fileInput.closest('.file-field');
            var name = label && label.querySelector('span:last-child strong');
            if (name && fileInput.files && fileInput.files.length) name.textContent = fileInput.files[0].name;
        });
    }

    var messageList = document.querySelector('.direct-message-list');
    if (messageList) messageList.scrollTop = messageList.scrollHeight;

    if (messageList && messageList.dataset.feedUrl) {
        var lastMessageId = parseInt(messageList.dataset.lastMessageId || '0', 10) || 0;
        var chatFeedStopped = false;
        var chatFeedTimer = 0;
        var feedStatus = document.querySelector('[data-feed-status]');

        function appendSafeMessageText(target, text) {
            var linkPattern = /https?:\/\/[^\s<>"']+/giu;
            var match;
            var cursor = 0;
            while ((match = linkPattern.exec(text)) !== null) {
                target.appendChild(document.createTextNode(text.slice(cursor, match.index)));
                var rawLink = match[0];
                var cleanLink = rawLink.replace(/[.,!?;:)\]}]+$/g, '');
                var trailing = rawLink.slice(cleanLink.length);
                var linkUrl;
                try {
                    linkUrl = new URL(cleanLink);
                } catch (error) {
                    linkUrl = null;
                }
                if (linkUrl && (linkUrl.protocol === 'http:' || linkUrl.protocol === 'https:')) {
                    var anchor = document.createElement('a');
                    anchor.href = linkUrl.href;
                    anchor.target = '_blank';
                    anchor.rel = 'noopener noreferrer';
                    anchor.textContent = cleanLink;
                    target.appendChild(anchor);
                } else {
                    target.appendChild(document.createTextNode(cleanLink));
                }
                target.appendChild(document.createTextNode(trailing));
                cursor = match.index + rawLink.length;
            }
            target.appendChild(document.createTextNode(text.slice(cursor)));
        }

        function appendFetchedMessage(message) {
            var mine = Number(message.sender_id) === Number(messageList.dataset.currentUserId);
            var article = document.createElement('article');
            article.className = 'direct-message' + (mine ? ' direct-message-mine' : '');
            article.dataset.messageId = String(message.id);
            var bubble = document.createElement('div');
            bubble.className = 'message-bubble';

            if (!mine) {
                var sender = document.createElement('span');
                sender.className = 'chat-sender';
                sender.textContent = message.sender_name;
                bubble.appendChild(sender);
            }
            if (message.message) {
                var paragraph = document.createElement('p');
                appendSafeMessageText(paragraph, message.message);
                bubble.appendChild(paragraph);
            }
            if (message.media_name) {
                var attachmentUrl = 'index.php?page=message_attachment&id=' + encodeURIComponent(message.id);
                if (message.media_mime && message.media_mime.indexOf('image/') === 0) {
                    var imageLink = document.createElement('a');
                    imageLink.className = 'chat-image-link';
                    imageLink.href = attachmentUrl;
                    var image = document.createElement('img');
                    image.src = attachmentUrl;
                    image.alt = message.media_name;
                    image.loading = 'lazy';
                    imageLink.appendChild(image);
                    bubble.appendChild(imageLink);
                } else if (message.media_mime && (message.media_mime.indexOf('audio/') === 0 || message.media_mime.indexOf('video/') === 0 || message.media_mime === 'application/ogg')) {
                    var audio = document.createElement('audio');
                    audio.controls = true;
                    audio.preload = 'metadata';
                    var source = document.createElement('source');
                    source.src = attachmentUrl;
                    source.type = message.media_mime;
                    audio.appendChild(source);
                    bubble.appendChild(audio);
                } else {
                    var fileLink = document.createElement('a');
                    fileLink.className = 'chat-file-link';
                    fileLink.href = attachmentUrl;
                    var fileIcon = document.createElement('span');
                    fileIcon.className = 'file-icon';
                    fileIcon.setAttribute('aria-hidden', 'true');
                    fileIcon.textContent = '⇩';
                    var fileDetails = document.createElement('span');
                    var fileTitle = document.createElement('strong');
                    fileTitle.textContent = message.media_name;
                    var fileHint = document.createElement('small');
                    fileHint.textContent = 'Download attachment';
                    fileDetails.appendChild(fileTitle);
                    fileDetails.appendChild(fileHint);
                    fileLink.appendChild(fileIcon);
                    fileLink.appendChild(fileDetails);
                    bubble.appendChild(fileLink);
                }
            }

            var time = document.createElement('time');
            time.dateTime = message.created_at;
            time.textContent = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(message.created_at));
            bubble.appendChild(time);
            article.appendChild(bubble);
            var emptyState = messageList.querySelector('.chat-empty');
            if (emptyState) emptyState.remove();
            messageList.appendChild(article);
        }

        function pollMessages() {
            window.fetch(messageList.dataset.feedUrl + '&after=' + encodeURIComponent(lastMessageId), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                if (!response.ok) {
                    if (response.status === 401 || response.status === 403 || response.status === 404) chatFeedStopped = true;
                    throw new Error('Message updates could not be loaded (HTTP ' + response.status + ').');
                }
                return response.json();
            }).then(function (messages) {
                if (feedStatus) feedStatus.textContent = '';
                messages.forEach(function (message) {
                    appendFetchedMessage(message);
                    lastMessageId = Math.max(lastMessageId, Number(message.id) || 0);
                });
                messageList.dataset.lastMessageId = String(lastMessageId);
                if (messages.length) messageList.scrollTop = messageList.scrollHeight;
            }).catch(function (error) {
                console.error(error);
                if (feedStatus) feedStatus.textContent = 'Live updates are temporarily unavailable. Refresh this page to reconnect.';
            }).finally(function () {
                if (!chatFeedStopped) chatFeedTimer = window.setTimeout(pollMessages, 7000);
            });
        }

        chatFeedTimer = window.setTimeout(pollMessages, 7000);
        window.addEventListener('beforeunload', function () {
            chatFeedStopped = true;
            window.clearTimeout(chatFeedTimer);
        });
    }

    var imageViewer = document.querySelector('[data-image-viewer]');
    var imageViewerImage = imageViewer && imageViewer.querySelector('[data-image-viewer-image]');
    var imageViewerFocusTarget = null;
    function closeImageViewer() {
        if (!imageViewer || imageViewer.hidden) return;
        imageViewer.hidden = true;
        document.body.classList.remove('image-view-open');
        if (imageViewerImage) imageViewerImage.removeAttribute('src');
        if (imageViewerFocusTarget && typeof imageViewerFocusTarget.focus === 'function') imageViewerFocusTarget.focus();
    }
    if (imageViewer && imageViewerImage) {
        document.addEventListener('click', function (event) {
                var link = event.target.closest && event.target.closest('.chat-image-link');
                if (!link) return;
                event.preventDefault();
                imageViewerFocusTarget = link;
                var preview = link.querySelector('img');
                imageViewerImage.src = link.href;
                imageViewerImage.alt = preview ? preview.alt : 'Shared chat photo';
                imageViewer.hidden = false;
                document.body.classList.add('image-view-open');
                var closeButton = imageViewer.querySelector('[data-image-viewer-close]');
                if (closeButton) closeButton.focus();
        });
        imageViewer.querySelectorAll('[data-image-viewer-close]').forEach(function (button) {
            button.addEventListener('click', closeImageViewer);
        });
        imageViewer.addEventListener('click', function (event) {
            if (event.target === imageViewer) closeImageViewer();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeImageViewer();
        });
    }

    var chatComposer = document.querySelector('[data-chat-composer]');
    if (chatComposer) {
        var chatMediaInput = chatComposer.querySelector('#chat-media');
        var cameraInput = chatComposer.querySelector('#chat-camera');
        var attachmentPreview = chatComposer.querySelector('[data-attachment-preview]');
        var attachmentName = chatComposer.querySelector('[data-attachment-name]');
        var voicePreview = chatComposer.querySelector('[data-voice-preview]');
        var voiceStatus = chatComposer.querySelector('[data-voice-status]');
        var recordButton = chatComposer.querySelector('[data-voice-record]');
        var chatSendButton = chatComposer.querySelector('.chat-send-button');
        var messageInput = chatComposer.querySelector('[data-chat-message]');
        var previewUrl = '';
        var activeRecorder = null;
        var activeStream = null;
        var recordingChunks = [];
        var recordingTimer = 0;

        function clearVoicePreview() {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = '';
            if (voicePreview) {
                voicePreview.pause();
                voicePreview.removeAttribute('src');
                voicePreview.hidden = true;
            }
        }

        function showSelectedAttachment(file) {
            if (!attachmentPreview || !attachmentName) return;
            attachmentName.textContent = file ? file.name : '';
            attachmentPreview.hidden = !file;
        }

        function resetChatAttachment() {
            if (chatMediaInput) chatMediaInput.value = '';
            if (cameraInput) cameraInput.value = '';
            showSelectedAttachment(null);
            clearVoicePreview();
            if (voiceStatus) voiceStatus.textContent = '';
        }

        chatComposer.querySelectorAll('[data-open-chat-file]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-open-chat-file'));
                if (input) input.click();
            });
        });

        [chatMediaInput, cameraInput].forEach(function (input) {
            if (!input) return;
            input.addEventListener('change', function () {
                var selectedFile = input.files && input.files[0];
                if (!selectedFile) return;
                var otherInput = input === chatMediaInput ? cameraInput : chatMediaInput;
                if (otherInput) otherInput.value = '';
                clearVoicePreview();
                showSelectedAttachment(selectedFile);
                if (voiceStatus) voiceStatus.textContent = '';
            });
        });

        var removeAttachment = chatComposer.querySelector('[data-remove-attachment]');
        if (removeAttachment) removeAttachment.addEventListener('click', resetChatAttachment);

        if (recordButton && chatMediaInput && voiceStatus) {
            recordButton.addEventListener('click', function () {
                if (activeRecorder && activeRecorder.state === 'recording') {
                    activeRecorder.stop();
                    recordButton.disabled = true;
                    voiceStatus.textContent = 'Saving your voice note…';
                    return;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
                    voiceStatus.textContent = 'Voice recording is not supported in this browser. You can attach an audio file instead.';
                    return;
                }

                voiceStatus.textContent = 'Waiting for microphone permission…';
                recordButton.disabled = true;
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    recordButton.disabled = false;
                    if (chatMediaInput) chatMediaInput.value = '';
                    if (cameraInput) cameraInput.value = '';
                    showSelectedAttachment(null);
                    clearVoicePreview();
                    activeStream = stream;
                    recordingChunks = [];
                    var recordingFailed = false;
                    var mimeTypes = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm', 'audio/ogg'];
                    var mimeType = mimeTypes.find(function (type) {
                        return MediaRecorder.isTypeSupported(type);
                    });
                    try {
                        activeRecorder = mimeType ? new MediaRecorder(stream, { mimeType: mimeType }) : new MediaRecorder(stream);
                    } catch (error) {
                        stream.getTracks().forEach(function (track) { track.stop(); });
                        activeStream = null;
                        voiceStatus.textContent = 'This browser could not start a voice recording. Try attaching an audio file instead.';
                        return;
                    }

                    activeRecorder.addEventListener('dataavailable', function (event) {
                        if (event.data && event.data.size) recordingChunks.push(event.data);
                    });
                    activeRecorder.addEventListener('error', function () {
                        recordingFailed = true;
                        voiceStatus.textContent = 'The browser encountered an error while recording. Try again or attach an audio file.';
                        if (activeRecorder && activeRecorder.state === 'recording') activeRecorder.stop();
                    });
                    activeRecorder.addEventListener('stop', function () {
                        if (activeStream) activeStream.getTracks().forEach(function (track) { track.stop(); });
                        activeStream = null;
                        activeRecorder = null;
                        window.clearTimeout(recordingTimer);
                        recordingTimer = 0;
                        recordButton.disabled = false;
                        if (chatSendButton) chatSendButton.disabled = false;
                        recordButton.textContent = '●';
                        recordButton.setAttribute('aria-pressed', 'false');
                        recordButton.setAttribute('aria-label', 'Record a voice note');

                        if (recordingFailed) {
                            recordingChunks = [];
                            return;
                        }
                        if (!recordingChunks.length) {
                            voiceStatus.textContent = 'No audio was captured. Check microphone permission and try again.';
                            return;
                        }

                        var blobType = (mimeType || 'audio/webm').split(';')[0];
                        var recording = new Blob(recordingChunks, { type: blobType });
                        if (recording.size > 10 * 1024 * 1024) {
                            voiceStatus.textContent = 'That recording is larger than 10 MB. Record a shorter note.';
                            return;
                        }
                        var extension = blobType.indexOf('ogg') !== -1 ? 'ogg' : 'webm';
                        var voiceFile = new File([recording], 'voice-note-' + Date.now() + '.' + extension, { type: blobType });
                        if (typeof DataTransfer === 'undefined' || typeof File === 'undefined') {
                            voiceStatus.textContent = 'This browser cannot prepare the recording for upload. Attach an audio file instead.';
                            return;
                        }
                        var transfer = new DataTransfer();
                        transfer.items.add(voiceFile);
                        chatMediaInput.files = transfer.files;
                        if (cameraInput) cameraInput.value = '';
                        showSelectedAttachment(voiceFile);
                        clearVoicePreview();
                        previewUrl = URL.createObjectURL(recording);
                        voicePreview.src = previewUrl;
                        voicePreview.hidden = false;
                        voiceStatus.textContent = 'Voice note ready. Preview it or send when you are ready.';
                    });

                    try {
                        activeRecorder.start();
                    } catch (error) {
                        stream.getTracks().forEach(function (track) { track.stop(); });
                        activeStream = null;
                        activeRecorder = null;
                        voiceStatus.textContent = 'This browser could not start a voice recording. Try attaching an audio file instead.';
                        return;
                    }
                    if (chatSendButton) chatSendButton.disabled = true;
                    recordButton.textContent = '■';
                    recordButton.setAttribute('aria-pressed', 'true');
                    recordButton.setAttribute('aria-label', 'Stop recording');
                    voiceStatus.textContent = 'Recording… select stop when you are finished. Maximum 2 minutes.';
                    recordingTimer = window.setTimeout(function () {
                        if (activeRecorder && activeRecorder.state === 'recording') {
                            activeRecorder.stop();
                            recordButton.disabled = true;
                            voiceStatus.textContent = 'Two-minute limit reached. Saving your voice note…';
                        }
                    }, 120000);
                }).catch(function () {
                    recordButton.disabled = false;
                    voiceStatus.textContent = 'Microphone access was not granted. Allow microphone access in your browser and try again.';
                });
            });
        }

        chatComposer.addEventListener('submit', function (event) {
            var hasMessage = messageInput && messageInput.value.trim() !== '';
            var hasMedia = (chatMediaInput && chatMediaInput.files && chatMediaInput.files.length) || (cameraInput && cameraInput.files && cameraInput.files.length);
            if (!hasMessage && !hasMedia) {
                event.preventDefault();
                if (voiceStatus) voiceStatus.textContent = 'Write a message or attach a voice note, photo, or file.';
                if (messageInput) messageInput.focus();
            }
        });

        if (messageInput) {
            messageInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    chatComposer.requestSubmit();
                }
            });
        }

        window.addEventListener('beforeunload', function () {
            if (activeRecorder && activeRecorder.state === 'recording') activeRecorder.stop();
            if (activeStream) activeStream.getTracks().forEach(function (track) { track.stop(); });
            clearVoicePreview();
        });
    }

    var statusFeed = document.querySelector('[data-status-feed]');
    if (statusFeed) {
        var statusPhotoInput = document.querySelector('input[name="status_media"]');
        var statusPhotoName = document.querySelector('[data-status-file-name]');
        var statusCompose = document.querySelector('[data-status-compose]');
        var statusText = document.querySelector('#status-message');
        var statusCsrf = statusFeed.querySelector('[data-status-csrf]');
        var mediaFeedback = document.querySelector('[data-status-media-feedback]');
        var videoMetadataPending = false;

        if (statusPhotoInput && statusPhotoName) {
            statusPhotoInput.addEventListener('change', function () {
                var file = statusPhotoInput.files && statusPhotoInput.files[0];
                statusPhotoName.textContent = file ? file.name : 'No media selected';
                statusPhotoInput.setCustomValidity('');
                videoMetadataPending = false;
                if (mediaFeedback) mediaFeedback.textContent = '';
                if (!file || !file.type || file.type.indexOf('video/') !== 0) return;
                if (file.size > 50 * 1024 * 1024) {
                    statusPhotoInput.setCustomValidity('Choose a video no larger than 50 MB.');
                    if (mediaFeedback) mediaFeedback.textContent = 'This video is over the 50 MB limit.';
                    return;
                }
                videoMetadataPending = true;
                if (mediaFeedback) mediaFeedback.textContent = 'Checking video length…';
                var videoUrl = URL.createObjectURL(file);
                var videoCheck = document.createElement('video');
                videoCheck.preload = 'metadata';
                videoCheck.onloadedmetadata = function () {
                    URL.revokeObjectURL(videoUrl);
                    videoMetadataPending = false;
                    if (videoCheck.duration > 120) {
                        statusPhotoInput.setCustomValidity('Trim this video to 2 minutes or less on your device before posting.');
                        if (mediaFeedback) mediaFeedback.textContent = 'This video is longer than 2 minutes. Trim it on your device, then select it again—or remove it to skip video.';
                    } else if (videoCheck.duration > 0) {
                        if (mediaFeedback) mediaFeedback.textContent = 'Video length: ' + Math.floor(videoCheck.duration / 60) + ':' + String(Math.floor(videoCheck.duration % 60)).padStart(2, '0') + '.';
                    } else {
                        statusPhotoInput.setCustomValidity('The video duration could not be verified.');
                        if (mediaFeedback) mediaFeedback.textContent = 'Could not verify the video length. Choose a valid MP4 or MOV file.';
                    }
                };
                videoCheck.onerror = function () {
                    URL.revokeObjectURL(videoUrl);
                    videoMetadataPending = false;
                    statusPhotoInput.setCustomValidity('Choose a valid MP4 or MOV video.');
                    if (mediaFeedback) mediaFeedback.textContent = 'Could not read this video. Choose an MP4 or MOV file.';
                };
                videoCheck.src = videoUrl;
            });
        }
        if (statusCompose) {
            statusCompose.addEventListener('submit', function (event) {
                var file = statusPhotoInput && statusPhotoInput.files && statusPhotoInput.files[0];
                var hasText = statusText && statusText.value.trim() !== '';
                if (!hasText && !file) {
                    event.preventDefault();
                    if (statusText) {
                        statusText.setCustomValidity('Write a status or add a photo.');
                        statusText.reportValidity();
                        statusText.addEventListener('input', function clearStatusValidity() {
                            statusText.setCustomValidity('');
                            statusText.removeEventListener('input', clearStatusValidity);
                        });
                    }
                    return;
                }
                if (videoMetadataPending) {
                    event.preventDefault();
                    if (mediaFeedback) mediaFeedback.textContent = 'Wait while the video length is checked.';
                    return;
                }
                if (file && file.type.indexOf('video/') === 0 && file.size > 50 * 1024 * 1024) {
                    event.preventDefault();
                    statusPhotoInput.setCustomValidity('Choose a video no larger than 50 MB.');
                    statusPhotoInput.reportValidity();
                } else if (file && file.type.indexOf('video/') !== 0 && file.size > 5 * 1024 * 1024) {
                    event.preventDefault();
                    statusPhotoInput.setCustomValidity('Choose a photo no larger than 5 MB.');
                    statusPhotoInput.reportValidity();
                }
            });
        }

        var viewCards = Array.prototype.slice.call(statusFeed.querySelectorAll('[data-status-card]')).filter(function (card) {
            return card.dataset.statusAuthor !== 'true';
        });
        function recordStatusView(card) {
            if (card.dataset.statusViewed === 'true' || card.dataset.statusViewPending === 'true' || !statusCsrf) return;
            card.dataset.statusViewPending = 'true';
            var formData = new FormData();
            formData.append('action', 'record_status_view');
            formData.append('csrf_token', statusCsrf.value);
            formData.append('status_id', card.dataset.statusCard);
            window.fetch(statusFeed.dataset.viewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                body: formData
            }).then(function (response) {
                if (!response.ok) throw new Error('Status view could not be recorded (HTTP ' + response.status + ').');
                return response.json();
            }).then(function () {
                card.dataset.statusViewed = 'true';
                delete card.dataset.statusViewPending;
            }).catch(function (error) {
                delete card.dataset.statusViewPending;
                console.error(error);
            });
        }

        var storyViewer = document.querySelector('[data-status-story-viewer]');
        var storyAuthor = storyViewer && storyViewer.querySelector('[data-story-author]');
        var storyAvatar = storyViewer && storyViewer.querySelector('[data-story-avatar]');
        var storyTime = storyViewer && storyViewer.querySelector('[data-story-time]');
        var storyMessage = storyViewer && storyViewer.querySelector('[data-story-message]');
        var storyImage = storyViewer && storyViewer.querySelector('[data-story-image]');
        var storyVideo = storyViewer && storyViewer.querySelector('[data-story-video]');
        var storyVideoHint = storyViewer && storyViewer.querySelector('[data-story-video-hint]');
        var storyProgress = storyViewer && storyViewer.querySelector('[data-story-progress]');
        var storyLike = storyViewer && storyViewer.querySelector('[data-story-like]');
        var storyTimer = 0;
        var storyCurrentGroup = [];
        var storyCurrentIndex = 0;
        var storyReturnFocus = null;
        var storyLikePending = false;

        function storyDataFromCard(card) {
            var image = card.querySelector('.status-image');
            var video = card.querySelector('.status-video-preview video');
            var avatar = card.querySelector('.status-card-header .avatar img');
            var likeButton = card.querySelector('.status-like-button');
            var likeCount = card.querySelector('[data-status-like-count]');
            return {
                id: card.dataset.statusCard,
                authorId: card.dataset.statusAuthorId,
                author: card.dataset.statusAuthorName,
                created: card.dataset.statusCreated,
                viewed: card.dataset.statusViewed === 'true',
                own: card.dataset.statusAuthor === 'true',
                text: (card.querySelector('.status-copy') || {}).textContent || '',
                image: image ? image.src : '',
                video: video ? video.src : '',
                avatar: avatar ? avatar.src : '',
                avatarInitial: (card.querySelector('.status-card-header .avatar') || {}).textContent || '?',
                liked: !!(likeButton && likeButton.classList.contains('is-liked')),
                likeCount: parseInt((likeCount || {}).textContent || '0', 10) || 0,
                card: card
            };
        }

        var statusStories = viewCards.map(storyDataFromCard);
        var storiesByAuthor = {};
        statusStories.forEach(function (story) {
            if (!storiesByAuthor[story.authorId]) storiesByAuthor[story.authorId] = [];
            storiesByAuthor[story.authorId].push(story);
        });
        Object.keys(storiesByAuthor).forEach(function (authorId) {
            storiesByAuthor[authorId].sort(function (left, right) {
                return new Date(left.created) - new Date(right.created);
            });
        });

        function appendStoryProgress(group, activeIndex, durationSeconds) {
            if (!storyProgress) return;
            while (storyProgress.firstChild) storyProgress.removeChild(storyProgress.firstChild);
            group.forEach(function (story, index) {
                var segment = document.createElement('span');
                segment.className = 'status-story-progress-segment' + (index < activeIndex ? ' is-complete' : '');
                var fill = document.createElement('i');
                if (index === activeIndex) {
                    fill.style.animationDuration = durationSeconds + 's';
                    segment.classList.add('is-active');
                }
                segment.appendChild(fill);
                storyProgress.appendChild(segment);
            });
        }

        function clearStoryTimer() {
            window.clearTimeout(storyTimer);
            storyTimer = 0;
            if (storyVideo) {
                storyVideo.pause();
                storyVideo.removeAttribute('src');
                storyVideo.load();
                storyVideo.onended = null;
                storyVideo.onloadedmetadata = null;
                storyVideo.onplay = null;
                storyVideo.onpause = null;
            }
        }

        function closeStoryViewer() {
            if (!storyViewer || storyViewer.hidden) return;
            clearStoryTimer();
            storyViewer.hidden = true;
            document.body.classList.remove('status-view-open');
            if (storyReturnFocus && typeof storyReturnFocus.focus === 'function') storyReturnFocus.focus();
        }

        function showStory(index) {
            if (!storyViewer || index < 0 || index >= storyCurrentGroup.length) {
                closeStoryViewer();
                return;
            }
            clearStoryTimer();
            storyCurrentIndex = index;
            var story = storyCurrentGroup[index];
            if (storyAuthor) storyAuthor.textContent = story.author;
            if (storyTime) storyTime.textContent = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(story.created));
            if (storyAvatar) {
                while (storyAvatar.firstChild) storyAvatar.removeChild(storyAvatar.firstChild);
                if (story.avatar) {
                    var authorImage = document.createElement('img');
                    authorImage.src = story.avatar;
                    authorImage.alt = '';
                    storyAvatar.appendChild(authorImage);
                } else {
                    storyAvatar.textContent = String(story.avatarInitial).trim().slice(0, 1).toUpperCase();
                }
            }
            if (storyMessage) {
                storyMessage.textContent = story.text;
                storyMessage.hidden = !story.text;
            }
            if (storyImage) {
                storyImage.hidden = !story.image;
                storyImage.src = story.image || '';
            }
            if (storyVideo) storyVideo.hidden = !story.video;
            if (storyVideoHint) {
                storyVideoHint.hidden = true;
                storyVideoHint.textContent = '';
            }
            if (storyLike) {
                storyLike.hidden = story.own;
                storyLike.disabled = storyLikePending;
                storyLike.classList.toggle('is-liked', story.liked);
                storyLike.textContent = (story.liked ? '♥ Liked · ' : '♡ Like · ') + story.likeCount;
                storyLike.dataset.statusId = story.id;
            }
            recordStatusView(story.card);
            if (story.video && storyVideo) {
                var videoDuration = 0;
                storyVideo.onloadedmetadata = function () {
                    videoDuration = storyVideo.duration;
                    if (!videoDuration || videoDuration > 120) {
                        if (storyVideoHint) {
                            storyVideoHint.hidden = false;
                            storyVideoHint.textContent = videoDuration > 120 ? 'This video exceeds the two-minute story limit.' : 'Tap play to start this video.';
                        }
                        appendStoryProgress(storyCurrentGroup, index, 120);
                        return;
                    }
                    appendStoryProgress(storyCurrentGroup, index, videoDuration);
                    var initialFill = storyProgress && storyProgress.querySelector('.is-active i');
                    if (initialFill) initialFill.style.animationPlayState = 'paused';
                    storyVideo.onended = function () { showStory(storyCurrentIndex + 1); };
                    storyVideo.onplay = function () {
                        var activeFill = storyProgress && storyProgress.querySelector('.is-active i');
                        if (activeFill) activeFill.style.animationPlayState = 'running';
                    };
                    storyVideo.onpause = function () {
                        var activeFill = storyProgress && storyProgress.querySelector('.is-active i');
                        if (activeFill) activeFill.style.animationPlayState = 'paused';
                    };
                    storyVideo.play().catch(function () {
                        if (storyVideoHint) {
                            storyVideoHint.hidden = false;
                            storyVideoHint.textContent = 'Tap play to watch this video.';
                        }
                    });
                };
                storyVideo.src = story.video;
                storyVideo.load();
            } else {
                appendStoryProgress(storyCurrentGroup, index, 30);
                storyTimer = window.setTimeout(function () { showStory(storyCurrentIndex + 1); }, 30000);
            }
        }

        function openStoryGroup(authorId, storyId, trigger) {
            var group = storiesByAuthor[authorId] || [];
            if (!group.length) return;
            storyCurrentGroup = group;
            storyReturnFocus = trigger || document.activeElement;
            var startIndex = storyId ? group.findIndex(function (story) { return story.id === String(storyId); }) : 0;
            storyViewer.hidden = false;
            document.body.classList.add('status-view-open');
            showStory(startIndex < 0 ? 0 : startIndex);
            var closeButton = storyViewer.querySelector('[data-story-close]');
            if (closeButton) closeButton.focus();
        }

        if (storyViewer) {
            document.querySelectorAll('[data-status-story-group]').forEach(function (button) {
                button.addEventListener('click', function () {
                    openStoryGroup(button.dataset.statusStoryGroup, '', button);
                });
            });
            statusFeed.querySelectorAll('[data-status-story-open]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var story = statusStories.find(function (item) { return item.id === button.dataset.statusStoryOpen; });
                    if (story) openStoryGroup(story.authorId, story.id, button);
                });
            });
            storyViewer.querySelectorAll('[data-story-close]').forEach(function (button) {
                button.addEventListener('click', closeStoryViewer);
            });
            var previousStory = storyViewer.querySelector('[data-story-previous]');
            var nextStory = storyViewer.querySelector('[data-story-next]');
            if (previousStory) previousStory.addEventListener('click', function () { showStory(storyCurrentIndex - 1); });
            if (nextStory) nextStory.addEventListener('click', function () { showStory(storyCurrentIndex + 1); });
            document.addEventListener('keydown', function (event) {
                if (storyViewer.hidden) return;
                if (event.key === 'Escape') closeStoryViewer();
                if (event.key === 'ArrowLeft') showStory(storyCurrentIndex - 1);
                if (event.key === 'ArrowRight') showStory(storyCurrentIndex + 1);
            });
            if (storyLike) {
                storyLike.addEventListener('click', function () {
                    if (storyLikePending || !statusCsrf) return;
                    storyLikePending = true;
                    storyLike.disabled = true;
                    var formData = new FormData();
                    formData.append('action', 'toggle_status_like');
                    formData.append('csrf_token', statusCsrf.value);
                    formData.append('status_id', storyLike.dataset.statusId);
                    formData.append('ajax', '1');
                    window.fetch(statusFeed.dataset.viewUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                        body: formData
                    }).then(function (response) {
                        if (!response.ok) throw new Error('Status reaction could not be saved.');
                        return response.json();
                    }).then(function (result) {
                        var story = storyCurrentGroup[storyCurrentIndex];
                        story.liked = !!result.liked;
                        story.likeCount = Number(result.count) || 0;
                        storyLike.classList.toggle('is-liked', story.liked);
                        storyLike.textContent = (story.liked ? '♥ Liked · ' : '♡ Like · ') + story.likeCount;
                        var cardButton = story.card.querySelector('.status-like-button');
                        var cardCount = story.card.querySelector('[data-status-like-count]');
                        if (cardButton) {
                            cardButton.classList.toggle('is-liked', story.liked);
                            cardButton.setAttribute('aria-pressed', story.liked ? 'true' : 'false');
                            cardButton.textContent = story.liked ? '♥ Liked' : '♡ Like';
                        }
                        if (cardCount) cardCount.textContent = story.likeCount + ' like' + (story.likeCount === 1 ? '' : 's');
                    }).catch(function (error) {
                        console.error(error);
                    }).finally(function () {
                        storyLikePending = false;
                        storyLike.disabled = false;
                    });
                });
            }
        }

        function refreshStatusViewers(details) {
            if (!details.dataset.viewersUrl || details.dataset.loading === 'true') return;
            details.dataset.loading = 'true';
            window.fetch(details.dataset.viewersUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Status viewers could not be loaded (HTTP ' + response.status + ').');
                return response.json();
            }).then(function (viewers) {
                var list = details.querySelector('[data-status-viewers-list]');
                var empty = details.querySelector('[data-status-no-viewers]');
                var total = details.querySelector('[data-viewer-total]');
                if (!list || !Array.isArray(viewers)) return;
                while (list.firstChild) list.removeChild(list.firstChild);
                viewers.forEach(function (viewer) {
                    var row = document.createElement('div');
                    var avatar = document.createElement('span');
                    avatar.className = 'avatar avatar-tiny';
                    if (viewer.avatar_url) {
                        var image = document.createElement('img');
                        image.src = viewer.avatar_url;
                        image.alt = '';
                        image.loading = 'lazy';
                        avatar.appendChild(image);
                    } else {
                        avatar.textContent = String(viewer.name || '?').slice(0, 1).toUpperCase();
                    }
                    var name = document.createElement('strong');
                    name.textContent = viewer.name;
                    var time = document.createElement('time');
                    time.dateTime = viewer.viewed_at;
                    time.textContent = viewer.label;
                    row.appendChild(avatar);
                    row.appendChild(name);
                    row.appendChild(time);
                    list.appendChild(row);
                });
                if (total) total.textContent = String(viewers.length);
                if (empty) empty.hidden = viewers.length > 0;
            }).catch(function (error) {
                console.error(error);
            }).finally(function () {
                delete details.dataset.loading;
            });
        }

        statusFeed.querySelectorAll('[data-viewers-url]').forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (details.open) refreshStatusViewers(details);
            });
        });

        var ownedCards = statusFeed.querySelectorAll('[data-status-author="true"]');
        if (ownedCards.length && statusFeed.dataset.updatesUrl) {
            var statusRefreshTimer = 0;
            function refreshOwnedStatuses() {
                window.fetch(statusFeed.dataset.updatesUrl, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' }
                }).then(function (response) {
                    if (!response.ok) throw new Error('Status updates could not be loaded (HTTP ' + response.status + ').');
                    return response.json();
                }).then(function (updates) {
                    updates.forEach(function (status) {
                        var card = document.getElementById('status-' + status.id);
                        var count = card && card.querySelector('[data-status-view-count]');
                        var details = card && card.querySelector('[data-viewers-url]');
                        var total = details && details.querySelector('[data-viewer-total]');
                        if (count) count.textContent = status.view_count + ' view' + (status.view_count === 1 ? '' : 's');
                        if (total) total.textContent = String(status.view_count);
                        if (details && details.open) refreshStatusViewers(details);
                    });
                }).catch(function (error) {
                    console.error(error);
                }).finally(function () {
                    statusRefreshTimer = window.setTimeout(refreshOwnedStatuses, 15000);
                });
            }
            statusRefreshTimer = window.setTimeout(refreshOwnedStatuses, 15000);
            window.addEventListener('beforeunload', function () { window.clearTimeout(statusRefreshTimer); });
        }
    }

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (event.defaultPrevented || !button || !form.checkValidity()) return;
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.classList.add('is-loading');
            window.setTimeout(function () {
                button.disabled = false;
                button.classList.remove('is-loading');
            }, 12000);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApp);
} else {
    initializeApp();
}
