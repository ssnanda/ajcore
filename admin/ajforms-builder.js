function initAJFormsBuilder() {
    const dropzone = document.getElementById('wpf-dropzone');
    const canvas = document.getElementById('wpf-canvas-fields');
    const emptyState = document.querySelector('.wpf-empty-state');
    const fieldSettingsPanel = document.getElementById('wpf-panel-field');
    const structureList = document.getElementById('wpf-structure-list');
    const undoBtn = document.getElementById('wpf-undo-btn');
    const redoBtn = document.getElementById('wpf-redo-btn');
    const toggleFieldsBtn = document.getElementById('wpf-toggle-fields-btn');
    const toggleStructureBtn = document.getElementById('wpf-toggle-structure-btn');
    const fieldsSidebar = document.getElementById('wpf-fields-sidebar');
    const previewLink = document.getElementById('wpf-preview-form-link');
    const fieldsSearchInput = document.querySelector('.wpf-fields-search input');
    const formSettingsToggle = document.getElementById('wpf-form-settings-toggle');
    const formSettingsMenu = document.getElementById('wpf-form-settings-menu');
    const formSectionTabs = Array.from(document.querySelectorAll('[data-settings-section-tab]'));

    if (!dropzone || !canvas || !fieldSettingsPanel) {
        return;
    }

    let formSchema = {
        version: 1,
        source: 'ajforms',
        title: 'Untitled Form',
        fields: [],
        settings: {
            submit_text: 'Submit',
            notifications_enabled: true,
            notification_email: '',
            notification_subject: 'New submission for {form_title}',
            notification_body: '{submission_table}{submission_details_table}',
            notification_from_name: '',
            notification_from_email: '',
            notification_reply_to: '',
            button_alignment: 'left',
            form_description: '',
            success_message: 'Form submitted successfully.',
            confirmation_mode: 'default',
            confirmation_type: 'message',
            redirect_url: '',
            confirmation_rules: [],
            use_label_placeholders: false,
            required_defaults_applied: false,
            custom_css: '',
            asana_task_enabled: false,
            asana_task_name: 'New form submission: {form_title}',
            asana_task_notes: 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}',
            asana_project_gid: '',
            asana_assignee_gid: '',
            asana_due_date: 'today',
            form_theme: 'clean',
            background_mode: 'solid',
            background_color: '#ffffff',
            background_gradient_start: '#ffffff',
            background_gradient_end: '#f3f7fb',
            primary_color: '#0f7ac6',
            text_color: '#1f2937',
            input_background: '#ffffff',
            input_border_color: '#d7dce3',
            border_radius: 16
        },
        sureforms: {}
    };

    let activeFieldId = null;
    let formId = null;
    let undoStack = [];
    let redoStack = [];
    let isRestoringHistory = false;
    let currentDropIndex = null;
    let resizingFieldId = null;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cloneDeep(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function createDefaultRuleAction(type = 'show_message') {
        return {
            type: type,
            message: type === 'show_message' ? 'Form submitted successfully.' : '',
            url: ''
        };
    }

    function createDefaultRuleCondition(fieldId = '') {
        return {
            field: fieldId,
            operator: 'equals',
            value: ''
        };
    }

    function createDefaultConfirmationRule() {
        return {
            id: 'rule_' + Math.random().toString(36).slice(2, 11),
            enabled: true,
            priority: ((formSchema.settings.confirmation_rules || []).length + 1) * 10,
            logic: 'AND',
            conditions: [createDefaultRuleCondition(formSchema.fields[0] ? formSchema.fields[0].id : '')],
            actions: [createDefaultRuleAction('show_message')],
            stop_processing: true
        };
    }

    function normalizeRuleAction(action) {
        const normalized = Object.assign(createDefaultRuleAction(), action || {});
        const allowedTypes = ['show_message', 'redirect', 'webhook'];
        normalized.type = allowedTypes.includes(normalized.type) ? normalized.type : 'show_message';
        normalized.message = String(normalized.message || '');
        normalized.url = String(normalized.url || '');
        return normalized;
    }

    function normalizeRuleCondition(condition) {
        const normalized = Object.assign(createDefaultRuleCondition(), condition || {});
        const allowedOperators = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'];
        normalized.field = String(normalized.field || '');
        normalized.operator = allowedOperators.includes(normalized.operator) ? normalized.operator : 'equals';
        normalized.value = String(normalized.value || '');
        return normalized;
    }

    function normalizeConfirmationRule(rule, index = 0) {
        const normalized = Object.assign(createDefaultConfirmationRule(), rule || {});
        normalized.id = normalized.id || ('rule_' + Math.random().toString(36).slice(2, 11));
        normalized.enabled = normalized.enabled !== false;
        normalized.priority = parseInt(normalized.priority, 10);
        normalized.priority = Number.isFinite(normalized.priority) ? normalized.priority : (index + 1) * 10;
        normalized.logic = String(normalized.logic || 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND';
        normalized.conditions = Array.isArray(normalized.conditions) && normalized.conditions.length
            ? normalized.conditions.map(normalizeRuleCondition)
            : [createDefaultRuleCondition(formSchema.fields[0] ? formSchema.fields[0].id : '')];
        normalized.actions = Array.isArray(normalized.actions) && normalized.actions.length
            ? normalized.actions.map(normalizeRuleAction)
            : [createDefaultRuleAction('show_message')];
        normalized.stop_processing = normalized.stop_processing !== false;
        return normalized;
    }

    function normalizeConfirmationRules(rules) {
        return Array.isArray(rules) ? rules.map(normalizeConfirmationRule) : [];
    }

    function slugifyFieldName(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 60);
    }

    function getOptionValue(label, fallbackIndex = 0) {
        return slugifyFieldName(label) || `option_${fallbackIndex + 1}`;
    }

    function getFieldNameBase(type) {
        const bases = {
            question: 'question',
            text: 'text',
            email: 'email',
            url: 'url',
            textarea: 'textarea',
            select: 'dropdown',
            checkboxes: 'multiple_choice',
            multiple_choice: 'single_choice',
            number: 'number',
            phone: 'phone',
            tel: 'phone',
            address: 'address',
            date: 'date',
            file: 'file',
            separator: 'separator'
        };

        return bases[type] || 'field';
    }

    function getUniqueFieldName(type, excludeFieldId = null) {
        const base = getFieldNameBase(type);
        const usedNames = new Set(
            formSchema.fields
                .filter((field) => field.id !== excludeFieldId)
                .map((field) => slugifyFieldName(field.field_name))
                .filter(Boolean)
        );

        let index = 1;
        let candidate = `${base}${index}`;

        while (usedNames.has(candidate)) {
            index += 1;
            candidate = `${base}${index}`;
        }

        return candidate;
    }

    function getActiveFieldIndex() {
        if (!activeFieldId) {
            return -1;
        }

        return formSchema.fields.findIndex((field) => field.id === activeFieldId);
    }

    function getPreferredInsertIndex() {
        const activeIndex = getActiveFieldIndex();
        if (activeIndex >= 0) {
            return activeIndex + 1;
        }

        return formSchema.fields.length;
    }

    function getCanvasFieldElements() {
        return Array.from(canvas.querySelectorAll('.wpf-canvas-field'));
    }

    function clearDropIndicator() {
        currentDropIndex = null;
        getCanvasFieldElements().forEach((fieldEl) => {
            fieldEl.classList.remove('wpf-drop-before', 'wpf-drop-after');
        });
    }

    function applyDropIndicator(insertIndex) {
        const fieldElements = getCanvasFieldElements();
        clearDropIndicator();

        currentDropIndex = insertIndex;

        if (!fieldElements.length || insertIndex === null) {
            return;
        }

        if (insertIndex <= 0) {
            fieldElements[0].classList.add('wpf-drop-before');
            return;
        }

        if (insertIndex >= fieldElements.length) {
            fieldElements[fieldElements.length - 1].classList.add('wpf-drop-after');
            return;
        }

        fieldElements[insertIndex].classList.add('wpf-drop-before');
    }

    function getInsertIndexFromPointer(clientX, clientY) {
        const fieldElements = getCanvasFieldElements();

        if (!fieldElements.length) {
            return 0;
        }

        for (let index = 0; index < fieldElements.length; index += 1) {
            const fieldEl = fieldElements[index];
            const rect = fieldEl.getBoundingClientRect();

            if (clientY >= rect.top && clientY <= rect.bottom) {
                if (clientX < rect.left + (rect.width / 2)) {
                    return index;
                }

                return index + 1;
            }

            if (clientY < rect.top) {
                return index;
            }
        }

        return fieldElements.length;
    }

    function formatFieldTypeLabel(type) {
        const labels = {
            question: 'Question',
            text: 'Text Field',
            email: 'Email Field',
            url: 'URL Field',
            textarea: 'Textarea Field',
            select: 'Dropdown Field',
            checkboxes: 'Multiple Choice',
            multiple_choice: 'Single Choice',
            number: 'Number Field',
            phone: 'Phone Field',
            tel: 'Phone Field',
            address: 'Address Field',
            date: 'Date Field',
            file: 'File Upload',
            separator: 'Separator'
        };

        return labels[type] || 'Field';
    }

    function normalizeField(field) {
        const normalized = Object.assign(
            {
                id: 'field_' + Math.random().toString(36).slice(2, 11),
                type: 'text',
                label: 'Text',
                field_name: '',
                placeholder: '',
                required: true,
                css_class: '',
                width: 100,
                help_text: '',
                default_value: '',
                conversational: null,
                conversation_step: '',
                branch_map: {},
                flow_rules: [],
                accepted_file_types: '.pdf,.jpg,.jpeg,.png,.gif,.webp'
            },
            field || {}
        );

        normalized.width = parseInt(normalized.width, 10);
        if (![100, 50, 33, 25].includes(normalized.width)) {
            normalized.width = 100;
        }

        normalized.field_name = slugifyFieldName(normalized.field_name || '');

        if (normalized.conversational === null || typeof normalized.conversational === 'undefined') {
            normalized.conversational = normalized.conversation_step
                ? normalized.conversation_step !== 'final_contact'
                : normalized.type === 'question';
        }

        normalized.conversational = !!normalized.conversational;
        normalized.conversation_step = normalized.conversational ? 'question' : 'final_contact';

        if (
            normalized.type === 'question' ||
            normalized.type === 'select' ||
            normalized.type === 'checkboxes' ||
            normalized.type === 'multiple_choice'
        ) {
            if (!Array.isArray(normalized.options) || !normalized.options.length) {
                normalized.options = normalized.type === 'question'
                    ? [
                        { label: 'Yes', value: 'yes' },
                        { label: 'No', value: 'no' }
                    ]
                    : [
                        { label: 'Option 1', value: 'option_1' },
                        { label: 'Option 2', value: 'option_2' }
                    ];
            } else {
                normalized.options = normalized.options.map((option, index) => {
                    if (typeof option === 'object' && option !== null) {
                        return {
                            label: option.label || ('Option ' + (index + 1)),
                            value: option.value || ('option_' + (index + 1))
                        };
                    }

                    return {
                        label: String(option),
                        value: String(option)
                    };
                });
            }
        }

        if (typeof normalized.branch_map !== 'object' || normalized.branch_map === null || Array.isArray(normalized.branch_map)) {
            normalized.branch_map = {};
        }

        if (!Array.isArray(normalized.flow_rules)) {
            normalized.flow_rules = Object.keys(normalized.branch_map).map((optionValue, index) => {
                const target = normalized.branch_map[optionValue];
                const actionType = target === '__end' ? 'end' : (target === '__action' ? 'action' : (target ? 'jump' : 'next'));
                return {
                    id: 'flow_' + normalized.id + '_' + index,
                    priority: (index + 1) * 10,
                    logic: 'AND',
                    conditions: [
                        {
                            field: normalized.id,
                            operator: 'equals',
                            value: optionValue
                        }
                    ],
                    actions: [
                        {
                            type: actionType,
                            target: actionType === 'jump' ? target : ''
                        }
                    ],
                    stop_processing: true
                };
            });
        }

        return normalized;
    }

    function normalizeFieldNames(fields) {
        const usedNames = new Set();
        const counters = {};

        return fields.map((field) => {
            const normalized = Object.assign({}, field);
            const base = getFieldNameBase(normalized.type);
            const currentName = slugifyFieldName(normalized.field_name || '');
            const shouldGenerateName = !currentName || currentName === base || usedNames.has(currentName);

            if (!shouldGenerateName) {
                normalized.field_name = currentName;
                usedNames.add(currentName);
                return normalized;
            }

            counters[base] = counters[base] || 1;

            let generatedName = `${base}${counters[base]}`;
            while (usedNames.has(generatedName)) {
                counters[base] += 1;
                generatedName = `${base}${counters[base]}`;
            }

            normalized.field_name = generatedName;
            usedNames.add(generatedName);
            counters[base] += 1;

            return normalized;
        });
    }

    function normalizeIncomingSchema(initialSchema) {
        if (Array.isArray(initialSchema)) {
            return {
                version: 1,
                source: 'legacy',
                fields: normalizeFieldNames(initialSchema.map(normalizeField)),
                settings: {
                    submit_text: 'Submit',
                    notifications_enabled: true,
                    notification_email: '',
                    notification_subject: 'New submission for {form_title}',
                    notification_body: '{submission_table}{submission_details_table}',
                    notification_from_name: '',
                    notification_from_email: '',
                    notification_reply_to: '',
                    button_alignment: 'left',
                    form_description: '',
                    success_message: 'Form submitted successfully.',
                    confirmation_mode: 'default',
                    confirmation_type: 'message',
                    redirect_url: '',
                    confirmation_rules: [],
                    use_label_placeholders: false,
                    required_defaults_applied: false,
                    custom_css: '',
                    asana_task_enabled: false,
                    asana_task_name: 'New form submission: {form_title}',
                    asana_task_notes: 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}',
                    asana_project_gid: '',
                    asana_assignee_gid: '',
                    asana_due_date: 'today',
                    stripe_enabled: false,
                    stripe_amount: '',
                    stripe_currency: 'usd',
                    stripe_description: 'Payment for {form_title}',
                    form_theme: 'clean',
                    background_mode: 'solid',
                    background_color: '#ffffff',
                    background_gradient_start: '#ffffff',
                    background_gradient_end: '#f3f7fb',
                    primary_color: '#0f7ac6',
                    text_color: '#1f2937',
                    input_background: '#ffffff',
                    input_border_color: '#d7dce3',
                    border_radius: 16
                },
                sureforms: {}
            };
        }

        if (initialSchema && Array.isArray(initialSchema.fields)) {
            return {
                version: initialSchema.version || 1,
                source: initialSchema.source || 'ajforms',
                fields: normalizeFieldNames((initialSchema.fields || []).map(normalizeField)),
                settings: Object.assign(
                    {
                        submit_text: 'Submit',
                        notifications_enabled: true,
                        notification_email: '',
                        notification_subject: 'New submission for {form_title}',
                        notification_body: '{submission_table}{submission_details_table}',
                        notification_from_name: '',
                        notification_from_email: '',
                        notification_reply_to: '',
                        button_alignment: 'left',
                        form_description: '',
                        success_message: 'Form submitted successfully.',
                        confirmation_mode: 'default',
                        confirmation_type: 'message',
                        redirect_url: '',
                        confirmation_rules: [],
                        use_label_placeholders: false,
                        required_defaults_applied: false,
                        custom_css: '',
                        asana_task_enabled: false,
                        asana_task_name: 'New form submission: {form_title}',
                        asana_task_notes: 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}',
                        asana_project_gid: '',
                        asana_assignee_gid: '',
                        asana_due_date: 'today',
                        stripe_enabled: false,
                        stripe_amount: '',
                        stripe_currency: 'usd',
                        stripe_description: 'Payment for {form_title}',
                        form_theme: 'clean',
                        background_mode: 'solid',
                        background_color: '#ffffff',
                        background_gradient_start: '#ffffff',
                        background_gradient_end: '#f3f7fb',
                        primary_color: '#0f7ac6',
                        text_color: '#1f2937',
                        input_background: '#ffffff',
                        input_border_color: '#d7dce3',
                        border_radius: 16
                    },
                    initialSchema.settings || {}
                ),
                sureforms: initialSchema.sureforms || {}
            };
        }

        return {
            version: 1,
            source: 'ajforms',
            fields: [],
            settings: {
                submit_text: 'Submit',
                notifications_enabled: true,
                notification_email: '',
                notification_subject: 'New submission for {form_title}',
                notification_body: '{submission_table}{submission_details_table}',
                notification_from_name: '',
                notification_from_email: '',
                notification_reply_to: '',
                button_alignment: 'left',
                form_description: '',
                success_message: 'Form submitted successfully.',
                confirmation_mode: 'default',
                confirmation_type: 'message',
                redirect_url: '',
                confirmation_rules: [],
                use_label_placeholders: false,
                required_defaults_applied: false,
                custom_css: '',
                asana_task_enabled: false,
                asana_task_name: 'New form submission: {form_title}',
                asana_task_notes: 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}',
                asana_project_gid: '',
                asana_assignee_gid: '',
                asana_due_date: 'today',
                stripe_enabled: false,
                stripe_amount: '',
                stripe_currency: 'usd',
                stripe_description: 'Payment for {form_title}',
                form_theme: 'clean',
                background_mode: 'solid',
                background_color: '#ffffff',
                background_gradient_start: '#ffffff',
                background_gradient_end: '#f3f7fb',
                primary_color: '#0f7ac6',
                text_color: '#1f2937',
                input_background: '#ffffff',
                input_border_color: '#d7dce3',
                border_radius: 16
            },
            sureforms: {}
        };
    }

    function applyCanvasTheme() {
        const settings = formSchema.settings || {};
        const borderRadius = parseInt(settings.border_radius, 10) || 16;
        const backgroundMode = settings.background_mode || 'solid';
        const backgroundValue = backgroundMode === 'gradient'
            ? `linear-gradient(135deg, ${settings.background_gradient_start || '#ffffff'} 0%, ${settings.background_gradient_end || '#f3f7fb'} 100%)`
            : (settings.background_color || '#ffffff');

        canvas.style.setProperty('--wpf-theme-primary', settings.primary_color || '#0f7ac6');
        canvas.style.setProperty('--wpf-theme-text', settings.text_color || '#1f2937');
        canvas.style.setProperty('--wpf-theme-input-bg', settings.input_background || '#ffffff');
        canvas.style.setProperty('--wpf-theme-input-border', settings.input_border_color || '#d7dce3');
        canvas.style.setProperty('--wpf-theme-radius', `${borderRadius}px`);
        canvas.style.setProperty('--wpf-theme-background', backgroundValue);
        canvas.setAttribute('data-theme', settings.form_theme || 'clean');
    }

    function getClosestFieldWidth(widthValue) {
        const snapPoints = [25, 33, 50, 100];
        let closest = snapPoints[0];

        snapPoints.forEach((snapPoint) => {
            if (Math.abs(snapPoint - widthValue) < Math.abs(closest - widthValue)) {
                closest = snapPoint;
            }
        });

        return closest;
    }

    function activateFormSettingsSection(sectionName) {
        const targetSection = sectionName || 'basics';
        const formTab = document.querySelector('.wpf-tab[data-target="form"]');
        const generalSubtab = document.querySelector('.wpf-inspector-subtab[data-form-subtab="general"]');

        if (formTab && !formTab.classList.contains('active')) {
            formTab.click();
        }

        if (generalSubtab && !generalSubtab.classList.contains('is-active')) {
            generalSubtab.click();
        }

        formSectionTabs.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.settingsSectionTab === targetSection);
        });

        document.querySelectorAll('[data-settings-section]').forEach((section) => {
            section.classList.toggle('is-active', section.dataset.settingsSection === targetSection);
        });
    }

    function getDefaultField(type, defaultLabel) {
        const field = normalizeField({
            id: 'field_' + Math.random().toString(36).slice(2, 11),
            type: type,
            label: defaultLabel,
            field_name: getUniqueFieldName(type),
            placeholder: '',
            required: true,
            css_class: '',
            width: 100
        });

        return field;
    }

    function snapshotState() {
        return JSON.stringify({
            formSchema: formSchema,
            activeFieldId: activeFieldId
        });
    }

    function updateUndoRedoButtons() {
        if (undoBtn) {
            undoBtn.disabled = undoStack.length === 0;
        }

        if (redoBtn) {
            redoBtn.disabled = redoStack.length === 0;
        }
    }

    function pushHistory() {
        if (isRestoringHistory) {
            return;
        }

        undoStack.push(snapshotState());

        if (undoStack.length > 100) {
            undoStack.shift();
        }

        redoStack = [];
        updateUndoRedoButtons();
    }

    function restoreState(snapshot) {
        try {
            isRestoringHistory = true;

            const parsed = JSON.parse(snapshot);
            formSchema = normalizeIncomingSchema(parsed.formSchema || {});
            formSchema.settings.confirmation_rules = normalizeConfirmationRules(formSchema.settings.confirmation_rules || []);
            activeFieldId = parsed.activeFieldId || null;

            if (activeFieldId && !formSchema.fields.find((field) => field.id === activeFieldId)) {
                activeFieldId = formSchema.fields.length ? formSchema.fields[0].id : null;
            }

            renderCanvas();

            if (activeFieldId) {
                const field = formSchema.fields.find((item) => item.id === activeFieldId);
                if (field) {
                    openFieldSettings(field);
                }
            } else {
                fieldSettingsPanel.innerHTML = '<p style="color:#646970;font-size:13px;">Select a field in the canvas to edit its settings.</p>';
            }
        } catch (error) {
            console.error('AJ Forms history restore failed', error);
        } finally {
            isRestoringHistory = false;
            updateUndoRedoButtons();
        }
    }

    function undoAction() {
        if (!undoStack.length) {
            return;
        }

        redoStack.push(snapshotState());
        restoreState(undoStack.pop());
    }

    function redoAction() {
        if (!redoStack.length) {
            return;
        }

        undoStack.push(snapshotState());
        restoreState(redoStack.pop());
    }

    if (window.ajFormsInitialData) {
        formId = window.ajFormsInitialData.form_id || null;
        formSchema.title = window.ajFormsInitialData.title || formSchema.title;

        const normalized = normalizeIncomingSchema(window.ajFormsInitialData.schema || {});
        formSchema.version = normalized.version;
        formSchema.source = normalized.source;
        formSchema.fields = normalized.fields;
        formSchema.settings = normalized.settings;
        formSchema.settings.confirmation_rules = normalizeConfirmationRules(formSchema.settings.confirmation_rules || []);
        formSchema.sureforms = normalized.sureforms;

        if (!formSchema.settings.required_defaults_applied) {
            formSchema.fields = formSchema.fields.map((field) => Object.assign({}, field, { required: true }));
            formSchema.settings.required_defaults_applied = true;
        }

        const titleInput = document.getElementById('wpf-form-title');
        if (titleInput) {
            titleInput.value = window.ajFormsInitialData.title || 'Untitled Form';
        }

        const submitTextInput = document.getElementById('wpf-form-submit-text');
        if (submitTextInput) {
            submitTextInput.value = formSchema.settings.submit_text || 'Submit';
        }

        const notificationsInput = document.getElementById('wpf-form-notifications');
        const notificationEmailInput = document.getElementById('wpf-form-notification-email');
        const notificationSubjectInput = document.getElementById('wpf-form-notification-subject');
        const notificationBodyInput = document.getElementById('wpf_form_notification_body');
        const notificationFromNameInput = document.getElementById('wpf-form-notification-from-name');
        const notificationFromEmailInput = document.getElementById('wpf-form-notification-from-email');
        const notificationReplyToInput = document.getElementById('wpf-form-notification-reply-to');
        const descriptionInput = document.getElementById('wpf-form-description');
        const successMessageInput = document.getElementById('wpf-form-success-message');
        const buttonAlignmentInput = document.getElementById('wpf-form-button-alignment');
        const confirmationModeInput = document.getElementById('wpf-form-confirmation-mode');
        const confirmationTypeInput = document.getElementById('wpf-form-confirmation-type');
        const redirectUrlInput = document.getElementById('wpf-form-redirect-url');
        const useLabelsAsPlaceholdersInput = document.getElementById('wpf-form-use-label-placeholders');
        const customCssInput = document.getElementById('wpf-form-custom-css');
        if (notificationsInput) {
            notificationsInput.checked = !!formSchema.settings.notifications_enabled;
        }
        if (notificationEmailInput) {
            notificationEmailInput.value = formSchema.settings.notification_email || '';
        }
        if (notificationSubjectInput) {
            notificationSubjectInput.value = formSchema.settings.notification_subject || 'New submission for {form_title}';
        }
        if (notificationBodyInput) {
            notificationBodyInput.value = formSchema.settings.notification_body || '{submission_table}{submission_details_table}';
        }
        if (notificationFromNameInput) {
            notificationFromNameInput.value = formSchema.settings.notification_from_name || '';
        }
        if (notificationFromEmailInput) {
            notificationFromEmailInput.value = formSchema.settings.notification_from_email || '';
        }
        if (notificationReplyToInput) {
            notificationReplyToInput.value = formSchema.settings.notification_reply_to || '';
        }
        if (descriptionInput) {
            descriptionInput.value = formSchema.settings.form_description || '';
        }
        if (successMessageInput) {
            successMessageInput.value = formSchema.settings.success_message || 'Form submitted successfully.';
        }
        if (buttonAlignmentInput) {
            buttonAlignmentInput.value = formSchema.settings.button_alignment || 'left';
        }
        if (confirmationModeInput) {
            confirmationModeInput.value = formSchema.settings.confirmation_mode || 'default';
        }
        if (confirmationTypeInput) {
            confirmationTypeInput.value = formSchema.settings.confirmation_type || 'message';
        }
        if (redirectUrlInput) {
            redirectUrlInput.value = formSchema.settings.redirect_url || '';
        }
        if (useLabelsAsPlaceholdersInput) {
            useLabelsAsPlaceholdersInput.checked = !!formSchema.settings.use_label_placeholders;
        }
        if (customCssInput) {
            customCssInput.value = formSchema.settings.custom_css || '';
        }
        const formThemeInput = document.getElementById('wpf-form-theme');
        const backgroundModeInput = document.getElementById('wpf-form-background-mode');
        const backgroundColorInput = document.getElementById('wpf-form-background-color');
        const gradientStartInput = document.getElementById('wpf-form-gradient-start');
        const gradientEndInput = document.getElementById('wpf-form-gradient-end');
        const primaryColorInput = document.getElementById('wpf-form-primary-color');
        const textColorInput = document.getElementById('wpf-form-text-color');
        const inputBackgroundInput = document.getElementById('wpf-form-input-background');
        const inputBorderInput = document.getElementById('wpf-form-input-border');
        const borderRadiusInput = document.getElementById('wpf-form-border-radius');
        const borderRadiusValue = document.getElementById('wpf-form-border-radius-value');
        const asanaTaskEnabledInput = document.getElementById('wpf-form-asana-task-enabled');
        const asanaTaskNameInput = document.getElementById('wpf-form-asana-task-name');
        const asanaTaskNotesInput = document.getElementById('wpf-form-asana-task-notes');
        const asanaProjectGidInput = document.getElementById('wpf-form-asana-project-gid');
        const asanaAssigneeGidInput = document.getElementById('wpf-form-asana-assignee-gid');
        const asanaDueDateInput = document.getElementById('wpf-form-asana-due-date');
        if (formThemeInput) {
            formThemeInput.value = formSchema.settings.form_theme || 'clean';
        }
        if (backgroundModeInput) {
            backgroundModeInput.value = formSchema.settings.background_mode || 'solid';
        }
        if (backgroundColorInput) {
            backgroundColorInput.value = formSchema.settings.background_color || '#ffffff';
        }
        if (gradientStartInput) {
            gradientStartInput.value = formSchema.settings.background_gradient_start || '#ffffff';
        }
        if (gradientEndInput) {
            gradientEndInput.value = formSchema.settings.background_gradient_end || '#f3f7fb';
        }
        if (primaryColorInput) {
            primaryColorInput.value = formSchema.settings.primary_color || '#0f7ac6';
        }
        if (textColorInput) {
            textColorInput.value = formSchema.settings.text_color || '#1f2937';
        }
        if (inputBackgroundInput) {
            inputBackgroundInput.value = formSchema.settings.input_background || '#ffffff';
        }
        if (inputBorderInput) {
            inputBorderInput.value = formSchema.settings.input_border_color || '#d7dce3';
        }
        if (borderRadiusInput) {
            borderRadiusInput.value = String(parseInt(formSchema.settings.border_radius, 10) || 16);
        }
        if (borderRadiusValue) {
            borderRadiusValue.textContent = String(parseInt(formSchema.settings.border_radius, 10) || 16);
        }
        if (asanaTaskEnabledInput) {
            asanaTaskEnabledInput.checked = !!formSchema.settings.asana_task_enabled;
        }
        if (asanaTaskNameInput) {
            asanaTaskNameInput.value = formSchema.settings.asana_task_name || 'New form submission: {form_title}';
        }
        if (asanaTaskNotesInput) {
            asanaTaskNotesInput.value = formSchema.settings.asana_task_notes || 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}';
        }
        if (asanaProjectGidInput) {
            asanaProjectGidInput.value = formSchema.settings.asana_project_gid || '';
        }
        if (asanaAssigneeGidInput) {
            asanaAssigneeGidInput.value = formSchema.settings.asana_assignee_gid || '';
        }
        if (asanaDueDateInput) {
            asanaDueDateInput.value = formSchema.settings.asana_due_date || 'today';
        }

        if (formSchema.fields.length) {
            activeFieldId = formSchema.fields[0].id;
        }
    }

    function renderFieldPreview(field) {
        const label = escapeHtml(field.label || '');
        const placeholder = escapeHtml((formSchema.settings.use_label_placeholders && field.label ? field.label : field.placeholder) || '');

        if (field.type === 'textarea') {
            return `
                <label class="wpf-preview-label">
                    ${label} ${field.required ? '<span class="wpf-required">*</span>' : ''}
                </label>
                <textarea placeholder="${placeholder}" disabled class="wpf-preview-control wpf-preview-textarea"></textarea>
            `;
        }

        if (field.type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            const renderedOptions = options.map((option) => {
                return `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`;
            }).join('');

            return `
                <label class="wpf-preview-label">
                    ${label} ${field.required ? '<span class="wpf-required">*</span>' : ''}
                </label>
                <select disabled class="wpf-preview-control">
                    <option>${placeholder || 'Select an option'}</option>
                    ${renderedOptions}
                </select>
            `;
        }

        if (field.type === 'question' || field.type === 'checkboxes' || field.type === 'multiple_choice') {
            const options = Array.isArray(field.options) ? field.options : [];
            const renderedOptions = options.map((option) => {
                return `
                    <label class="wpf-preview-choice">
                        <input type="${field.type === 'checkboxes' ? 'checkbox' : 'radio'}" disabled>
                        <span>${escapeHtml(option.label)}</span>
                    </label>
                `;
            }).join('');

            return `
                <label class="wpf-preview-label">
                    ${label} ${field.required ? '<span class="wpf-required">*</span>' : ''}
                </label>
                <div>${renderedOptions}</div>
            `;
        }

        if (field.type === 'separator') {
            return '<hr class="wpf-preview-separator">';
        }

        if (field.type === 'file') {
            return `
                <label class="wpf-preview-label">
                    ${label} ${field.required ? '<span class="wpf-required">*</span>' : ''}
                </label>
                <input type="file" disabled class="wpf-preview-control">
            `;
        }

        const inputTypeMap = {
            email: 'email',
            url: 'url',
            number: 'number',
            phone: 'tel',
            tel: 'tel',
            date: 'date'
        };

        const inputType = inputTypeMap[field.type] || 'text';

        return `
            <label class="wpf-preview-label">
                ${label} ${field.required ? '<span class="wpf-required">*</span>' : ''}
            </label>
            <input type="${inputType}" placeholder="${placeholder}" disabled class="wpf-preview-control">
        `;
    }

    function renderStructureList() {
        if (!structureList) {
            return;
        }

        if (!formSchema.fields.length) {
            structureList.innerHTML = '<p class="wpf-structure-empty">No fields added yet.</p>';
            return;
        }

        structureList.innerHTML = formSchema.fields.map((field, index) => `
            <div class="wpf-structure-item ${field.id === activeFieldId ? 'active' : ''}" data-id="${field.id}">
                <div class="wpf-structure-lines">
                    <div class="wpf-structure-line"><span>Field Type</span><strong>${escapeHtml(formatFieldTypeLabel(field.type))}</strong></div>
                    <div class="wpf-structure-line"><span>Field Name</span><strong>${escapeHtml(field.field_name || getFieldNameBase(field.type) + (index + 1))}</strong></div>
                    <div class="wpf-structure-badges">
                        <span class="${field.required ? 'is-on' : 'is-off'}">Req ${field.required ? 'Yes' : 'No'}</span>
                        <span class="${field.conversational ? 'is-on' : 'is-off'}">Conv ${field.conversational ? 'Yes' : 'No'}</span>
                    </div>
                </div>
            </div>
        `).join('');

        structureList.querySelectorAll('.wpf-structure-item').forEach((item) => {
            item.addEventListener('click', () => {
                const field = formSchema.fields.find((entry) => entry.id === item.dataset.id);
                if (!field) {
                    return;
                }

                activeFieldId = field.id;
                renderCanvas();
                openFieldSettings(field);
            });
        });
    }

    function renderNotificationVariables() {
        const variablesNode = document.getElementById('wpf-notification-variables');
        if (!variablesNode) {
            return;
        }

        const variables = [
            '{form_title}',
            '{submission_fields}',
            '{submission_table}',
            '{submission_details}',
            '{submission_details_table}',
            '{submitted_at}'
        ];

        formSchema.fields.forEach((field, index) => {
            variables.push(`{field_${index + 1}}`);
            if (field.field_name) {
                variables.push(`{${field.field_name}}`);
            }
        });

        variablesNode.innerHTML = '<strong>Available variables</strong>' + variables.map((variable) => `<code>${escapeHtml(variable)}</code>`).join('');
    }

    function getRuleFieldOptions(selectedFieldId = '') {
        return formSchema.fields
            .filter((field) => field && field.type !== 'separator')
            .map((field) => `<option value="${escapeHtml(field.id)}" ${field.id === selectedFieldId ? 'selected' : ''}>${escapeHtml(field.label || field.field_name || formatFieldTypeLabel(field.type))}</option>`)
            .join('');
    }

    function getConditionValueInput(condition) {
        const field = formSchema.fields.find((item) => item.id === condition.field);
        const operator = condition.operator || 'equals';

        if (operator === 'is_empty' || operator === 'is_not_empty') {
            return '<input type="text" class="wpf-rule-condition-value" value="" disabled placeholder="No value needed">';
        }

        if (field && Array.isArray(field.options) && field.options.length) {
            return `
                <select class="wpf-rule-condition-value">
                    <option value="">Choose value</option>
                    ${field.options.map((option) => {
                        const label = option.label || option.value || '';
                        return `<option value="${escapeHtml(label)}" ${condition.value === label ? 'selected' : ''}>${escapeHtml(label)}</option>`;
                    }).join('')}
                </select>
            `;
        }

        return `<input type="text" class="wpf-rule-condition-value" value="${escapeHtml(condition.value || '')}" placeholder="Value">`;
    }

    function renderConfirmationRules(openRuleId = '') {
        const node = document.getElementById('wpf-confirmation-rules');
        if (!node) {
            return;
        }

        const rules = normalizeConfirmationRules(formSchema.settings.confirmation_rules || []);
        formSchema.settings.confirmation_rules = rules;

        if (!rules.length) {
            node.innerHTML = '<p class="wpf-setting-help">No rules yet.</p>';
            return;
        }

        node.innerHTML = rules.map((rule, ruleIndex) => `
            <details class="wpf-field-settings-card wpf-confirmation-rule" data-rule-index="${ruleIndex}" ${openRuleId && rule.id === openRuleId ? 'open' : ''}>
                <summary class="wpf-rule-summary">
                    <span>${escapeHtml(rule.name || ('Rule ' + (ruleIndex + 1)))}</span>
                    <span>Priority ${escapeHtml(rule.priority)}</span>
                </summary>
                <div class="wpf-rule-body">
                <div class="wpf-field-settings-grid">
                    <div class="wpf-setting-row">
                        <label>Rule Name</label>
                        <input type="text" class="wpf-rule-input" data-key="name" value="${escapeHtml(rule.name || ('Rule ' + (ruleIndex + 1)))}">
                    </div>
                    <div class="wpf-setting-row">
                        <label>Priority</label>
                        <input type="number" class="wpf-rule-input" data-key="priority" value="${escapeHtml(rule.priority)}">
                    </div>
                </div>
                <div class="wpf-field-settings-grid">
                    <label class="wpf-toggle-card">
                        <strong>Enabled</strong>
                        <input type="checkbox" class="wpf-rule-checkbox" data-key="enabled" ${rule.enabled ? 'checked' : ''}>
                    </label>
                    <label class="wpf-toggle-card">
                        <strong>Stop Processing</strong>
                        <input type="checkbox" class="wpf-rule-checkbox" data-key="stop_processing" ${rule.stop_processing ? 'checked' : ''}>
                    </label>
                </div>
                <div class="wpf-setting-row">
                    <label>Condition Logic</label>
                    <select class="wpf-rule-select" data-key="logic">
                        <option value="AND" ${rule.logic === 'AND' ? 'selected' : ''}>All conditions match</option>
                        <option value="OR" ${rule.logic === 'OR' ? 'selected' : ''}>Any condition matches</option>
                    </select>
                </div>
                <div class="wpf-setting-row">
                    <label>Conditions</label>
                    <div class="wpf-options-editor">
                        ${rule.conditions.map((condition, conditionIndex) => `
                            <div class="wpf-rule-condition" data-condition-index="${conditionIndex}">
                                <select class="wpf-rule-condition-field">${getRuleFieldOptions(condition.field)}</select>
                                <select class="wpf-rule-condition-operator">
                                    <option value="equals" ${condition.operator === 'equals' ? 'selected' : ''}>equals</option>
                                    <option value="not_equals" ${condition.operator === 'not_equals' ? 'selected' : ''}>does not equal</option>
                                    <option value="contains" ${condition.operator === 'contains' ? 'selected' : ''}>contains</option>
                                    <option value="not_contains" ${condition.operator === 'not_contains' ? 'selected' : ''}>does not contain</option>
                                    <option value="is_empty" ${condition.operator === 'is_empty' ? 'selected' : ''}>is empty</option>
                                    <option value="is_not_empty" ${condition.operator === 'is_not_empty' ? 'selected' : ''}>is not empty</option>
                                </select>
                                ${getConditionValueInput(condition)}
                                <button type="button" class="wpf-option-delete wpf-rule-remove-condition">Remove</button>
                            </div>
                        `).join('')}
                    </div>
                    <button type="button" class="wpf-btn wpf-btn-secondary wpf-rule-add-condition">Add Condition</button>
                </div>
                <div class="wpf-setting-row">
                    <label>Actions</label>
                    <div class="wpf-options-editor">
                        ${rule.actions.map((action, actionIndex) => `
                            <div class="wpf-rule-action" data-action-index="${actionIndex}">
                                <select class="wpf-rule-action-type">
                                    <option value="show_message" ${action.type === 'show_message' ? 'selected' : ''}>Show message</option>
                                    <option value="redirect" ${action.type === 'redirect' ? 'selected' : ''}>Redirect to URL</option>
                                    <option value="webhook" ${action.type === 'webhook' ? 'selected' : ''}>Trigger webhook</option>
                                </select>
                                ${action.type === 'show_message'
                                    ? `<textarea class="wpf-rule-action-message" rows="2" placeholder="Message">${escapeHtml(action.message || '')}</textarea>`
                                    : `<input type="text" class="wpf-rule-action-url" value="${escapeHtml(action.url || '')}" placeholder="${action.type === 'webhook' ? 'Webhook URL' : 'Redirect URL'}">`
                                }
                                <button type="button" class="wpf-option-delete wpf-rule-remove-action">Remove</button>
                            </div>
                        `).join('')}
                    </div>
                    <button type="button" class="wpf-btn wpf-btn-secondary wpf-rule-add-action">Add Action</button>
                </div>
                <button type="button" class="wpf-option-delete wpf-rule-remove-rule">Delete Rule</button>
                </div>
            </details>
        `).join('');

        bindConfirmationRules();
    }

    function updateConfirmationModePanels() {
        const mode = document.getElementById('wpf-form-confirmation-mode')?.value || 'default';
        document.querySelectorAll('[data-confirmation-mode-panel]').forEach((panel) => {
            panel.style.display = panel.dataset.confirmationModePanel === mode ? '' : 'none';
        });
    }

    function bindConfirmationRules() {
        const node = document.getElementById('wpf-confirmation-rules');
        if (!node) {
            return;
        }

        node.querySelectorAll('.wpf-confirmation-rule').forEach((ruleEl) => {
            const ruleIndex = parseInt(ruleEl.dataset.ruleIndex, 10);
            const rule = formSchema.settings.confirmation_rules[ruleIndex];
            if (!rule) {
                return;
            }

            ruleEl.querySelectorAll('.wpf-rule-input, .wpf-rule-select').forEach((input) => {
                input.addEventListener('change', () => {
                    rule[input.dataset.key] = input.dataset.key === 'priority' ? parseInt(input.value || '0', 10) : input.value;
                    renderConfirmationRules(rule.id);
                });
            });

            ruleEl.querySelectorAll('.wpf-rule-checkbox').forEach((input) => {
                input.addEventListener('change', () => {
                    rule[input.dataset.key] = !!input.checked;
                });
            });

            ruleEl.querySelectorAll('.wpf-rule-condition').forEach((conditionEl) => {
                const conditionIndex = parseInt(conditionEl.dataset.conditionIndex, 10);
                const condition = rule.conditions[conditionIndex];

                conditionEl.querySelectorAll('.wpf-rule-condition-field, .wpf-rule-condition-operator, .wpf-rule-condition-value').forEach((input) => {
                    input.addEventListener('change', () => {
                        condition.field = conditionEl.querySelector('.wpf-rule-condition-field')?.value || '';
                        condition.operator = conditionEl.querySelector('.wpf-rule-condition-operator')?.value || 'equals';
                        condition.value = conditionEl.querySelector('.wpf-rule-condition-value')?.value || '';
                        renderConfirmationRules(rule.id);
                    });
                });

                conditionEl.querySelector('.wpf-rule-remove-condition')?.addEventListener('click', () => {
                    rule.conditions.splice(conditionIndex, 1);
                    if (!rule.conditions.length) {
                        rule.conditions.push(createDefaultRuleCondition(formSchema.fields[0] ? formSchema.fields[0].id : ''));
                    }
                    renderConfirmationRules(rule.id);
                });
            });

            ruleEl.querySelector('.wpf-rule-add-condition')?.addEventListener('click', () => {
                rule.conditions.push(createDefaultRuleCondition(formSchema.fields[0] ? formSchema.fields[0].id : ''));
                renderConfirmationRules(rule.id);
            });

            ruleEl.querySelectorAll('.wpf-rule-action').forEach((actionEl) => {
                const actionIndex = parseInt(actionEl.dataset.actionIndex, 10);
                const action = rule.actions[actionIndex];

                actionEl.querySelectorAll('.wpf-rule-action-type, .wpf-rule-action-message, .wpf-rule-action-url').forEach((input) => {
                    input.addEventListener('change', () => {
                        action.type = actionEl.querySelector('.wpf-rule-action-type')?.value || 'show_message';
                        action.message = actionEl.querySelector('.wpf-rule-action-message')?.value || '';
                        action.url = actionEl.querySelector('.wpf-rule-action-url')?.value || '';
                        renderConfirmationRules(rule.id);
                    });
                });

                actionEl.querySelector('.wpf-rule-remove-action')?.addEventListener('click', () => {
                    rule.actions.splice(actionIndex, 1);
                    if (!rule.actions.length) {
                        rule.actions.push(createDefaultRuleAction());
                    }
                    renderConfirmationRules(rule.id);
                });
            });

            ruleEl.querySelector('.wpf-rule-add-action')?.addEventListener('click', () => {
                rule.actions.push(createDefaultRuleAction('show_message'));
                renderConfirmationRules(rule.id);
            });

            ruleEl.querySelector('.wpf-rule-remove-rule')?.addEventListener('click', () => {
                formSchema.settings.confirmation_rules.splice(ruleIndex, 1);
                renderConfirmationRules();
            });
        });
    }

    function collectConfirmationRulesFromDom() {
        const node = document.getElementById('wpf-confirmation-rules');
        if (!node) {
            return normalizeConfirmationRules(formSchema.settings.confirmation_rules || []);
        }

        const rules = [];
        node.querySelectorAll('.wpf-confirmation-rule').forEach((ruleEl) => {
            const conditions = [];
            ruleEl.querySelectorAll('.wpf-rule-condition').forEach((conditionEl) => {
                conditions.push({
                    field: conditionEl.querySelector('.wpf-rule-condition-field')?.value || '',
                    operator: conditionEl.querySelector('.wpf-rule-condition-operator')?.value || 'equals',
                    value: conditionEl.querySelector('.wpf-rule-condition-value')?.value || ''
                });
            });

            const actions = [];
            ruleEl.querySelectorAll('.wpf-rule-action').forEach((actionEl) => {
                actions.push({
                    type: actionEl.querySelector('.wpf-rule-action-type')?.value || 'show_message',
                    message: actionEl.querySelector('.wpf-rule-action-message')?.value || '',
                    url: actionEl.querySelector('.wpf-rule-action-url')?.value || ''
                });
            });

            rules.push({
                id: formSchema.settings.confirmation_rules[parseInt(ruleEl.dataset.ruleIndex, 10)]?.id || '',
                name: ruleEl.querySelector('.wpf-rule-input[data-key="name"]')?.value || '',
                priority: parseInt(ruleEl.querySelector('.wpf-rule-input[data-key="priority"]')?.value || '0', 10),
                enabled: !!ruleEl.querySelector('.wpf-rule-checkbox[data-key="enabled"]')?.checked,
                stop_processing: !!ruleEl.querySelector('.wpf-rule-checkbox[data-key="stop_processing"]')?.checked,
                logic: ruleEl.querySelector('.wpf-rule-select[data-key="logic"]')?.value || 'AND',
                conditions: conditions,
                actions: actions
            });
        });

        return normalizeConfirmationRules(rules);
    }

    function renderCanvas() {
        applyCanvasTheme();
        canvas.querySelectorAll('.wpf-canvas-field').forEach((el) => el.remove());
        canvas.querySelectorAll('.wpf-canvas-submit-preview').forEach((el) => el.remove());

        if (formSchema.fields.length) {
            if (emptyState) {
                emptyState.style.display = 'none';
            }
        } else {
            if (emptyState) {
                emptyState.style.display = 'block';
            }
            activeFieldId = null;
            fieldSettingsPanel.innerHTML = '<p style="color:#646970;font-size:13px;">Select a field in the canvas to edit its settings.</p>';
            clearDropIndicator();
        }

        formSchema.fields.forEach((field) => {
            const width = [100, 50, 33, 25].includes(parseInt(field.width, 10)) ? parseInt(field.width, 10) : 100;
            const fieldEl = document.createElement('div');

            fieldEl.className = `wpf-canvas-field wpf-field-width-${width} ${field.id === activeFieldId ? 'active' : ''}`;
            fieldEl.dataset.id = field.id;

            fieldEl.innerHTML = `
                <div class="wpf-field-preview">
                    ${renderFieldPreview(field)}
                </div>
                <div class="wpf-field-width-badge">${width}%</div>
                <button class="wpf-field-resize-handle" type="button" title="Resize field width" aria-label="Resize field width">
                    <span></span>
                </button>
                <div class="wpf-field-actions">
                    <button class="wpf-action-btn duplicate" type="button" title="Duplicate">⎘</button>
                    <button class="wpf-action-btn delete" type="button" title="Delete">✕</button>
                </div>
            `;

            fieldEl.addEventListener('click', (e) => {
                e.stopPropagation();

                if (e.target.classList.contains('delete')) {
                    pushHistory();

                    formSchema.fields = formSchema.fields.filter((entry) => entry.id !== field.id);

                    if (activeFieldId === field.id) {
                        activeFieldId = formSchema.fields.length ? formSchema.fields[0].id : null;
                    }

                    renderCanvas();

                    if (activeFieldId) {
                        const nextField = formSchema.fields.find((entry) => entry.id === activeFieldId);
                        if (nextField) {
                            openFieldSettings(nextField);
                        }
                    }

                    return;
                }

                if (e.target.classList.contains('duplicate')) {
                    pushHistory();

                    const duplicated = cloneDeep(field);
                    duplicated.id = 'field_' + Math.random().toString(36).slice(2, 11);
                    duplicated.field_name = getUniqueFieldName(duplicated.type);

                    const index = formSchema.fields.findIndex((entry) => entry.id === field.id);
                    formSchema.fields.splice(index + 1, 0, duplicated);
                    activeFieldId = duplicated.id;

                    renderCanvas();
                    openFieldSettings(duplicated);
                    return;
                }

                activeFieldId = field.id;
                renderCanvas();
                openFieldSettings(field);
            });

            canvas.appendChild(fieldEl);

            const resizeHandle = fieldEl.querySelector('.wpf-field-resize-handle');
            if (resizeHandle) {
                resizeHandle.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    activeFieldId = field.id;
                    resizingFieldId = field.id;
                    renderCanvas();
                    openFieldSettings(field);

                    const startX = event.clientX;
                    const startWidth = parseInt(field.width, 10) || 100;
                    const canvasWidth = canvas.getBoundingClientRect().width || 1;
                    document.body.classList.add('wpf-is-resizing-field');

                    function onPointerMove(moveEvent) {
                        moveEvent.preventDefault();

                        const deltaPercent = ((moveEvent.clientX - startX) / canvasWidth) * 100;
                        const rawWidth = startWidth + deltaPercent;
                        const closest = getClosestFieldWidth(rawWidth);

                        if (field.width !== closest) {
                            field.width = closest;
                            renderCanvas();
                            openFieldSettings(field);
                        }
                    }

                    function onPointerUp() {
                        resizingFieldId = null;
                        document.body.classList.remove('wpf-is-resizing-field');
                        window.removeEventListener('pointermove', onPointerMove);
                        window.removeEventListener('pointerup', onPointerUp);
                        window.removeEventListener('pointercancel', onPointerUp);
                        renderCanvas();
                    }

                    pushHistory();
                    window.addEventListener('pointermove', onPointerMove);
                    window.addEventListener('pointerup', onPointerUp);
                    window.addEventListener('pointercancel', onPointerUp);
                });
            }
        });

        const submitPreview = document.createElement('div');
        const alignment = formSchema.settings.button_alignment || 'left';
        submitPreview.className = 'wpf-canvas-submit-preview';
        submitPreview.style.textAlign = alignment;
        submitPreview.innerHTML = `<button type="button" class="wpf-preview-submit-btn">${escapeHtml(formSchema.settings.submit_text || 'Submit')}</button>`;
        canvas.appendChild(submitPreview);

        renderStructureList();
        renderNotificationVariables();

        if (currentDropIndex !== null) {
            applyDropIndicator(currentDropIndex);
        }
    }

    function renderOptionsEditor(field) {
        const options = Array.isArray(field.options) ? field.options : [];

        return `
            <div class="wpf-setting-row">
                <label>Options</label>
                <div id="wpf-options-editor">
                    ${options.map((option, index) => `
                        <div class="wpf-option-row">
                            <input type="text" class="wpf-option-label" data-index="${index}" value="${escapeHtml(option.label)}" placeholder="Label">
                            <button type="button" class="button-link-delete wpf-option-delete" data-index="${index}">Remove</button>
                        </div>
                    `).join('')}
                    <button type="button" class="button button-secondary" id="wpf-add-option">Add Option</button>
                </div>
            </div>
        `;
    }

    function bindOptionsEditor(field) {
        const addOptionBtn = document.getElementById('wpf-add-option');
        if (addOptionBtn) {
            addOptionBtn.addEventListener('click', () => {
                pushHistory();

                const label = `Option ${field.options.length + 1}`;
                field.options.push({
                    label: label,
                    value: getOptionValue(label, field.options.length)
                });

                openFieldSettings(field);
                renderCanvas();
            });
        }

        fieldSettingsPanel.querySelectorAll('.wpf-option-label').forEach((input) => {
            let pushed = false;

            input.addEventListener('focus', () => {
                pushed = false;
            });

            input.addEventListener('input', (e) => {
                if (!pushed) {
                    pushHistory();
                    pushed = true;
                }

                const index = parseInt(e.target.dataset.index, 10);
                field.options[index].label = e.target.value;
                renderCanvas();
            });
        });

        fieldSettingsPanel.querySelectorAll('.wpf-option-delete').forEach((button) => {
            button.addEventListener('click', (e) => {
                pushHistory();

                const index = parseInt(e.target.dataset.index, 10);
                field.options.splice(index, 1);
                openFieldSettings(field);
                renderCanvas();
            });
        });
    }

    function getBranchTargetOptions(currentField) {
        const choices = [
            '<option value="">Next question in order</option>',
            '<option value="__contact">Final contact step</option>',
            '<option value="__end">End form / submit</option>',
            '<option value="__action">Action hook, then next</option>'
        ];

        formSchema.fields.forEach((candidate) => {
            if (!candidate || candidate.id === currentField.id || !candidate.conversational || candidate.type === 'separator') {
                return;
            }

            choices.push(`<option value="${escapeHtml(candidate.id)}">${escapeHtml(candidate.label || formatFieldTypeLabel(candidate.type))}</option>`);
        });

        return choices.join('');
    }

    function renderBranchEditor(field) {
        const options = Array.isArray(field.options) ? field.options : [];
        const targetOptions = getBranchTargetOptions(field);
        const checkboxHelp = field.type === 'checkboxes'
            ? '<p class="wpf-setting-help">If visitors choose more than one option, the first selected option with a flow rule is used.</p>'
            : '';

        return `
            <div class="wpf-options-editor">
                ${checkboxHelp}
                ${options.map((option) => {
                    const value = option.value || '';
                    const selectedValue = field.branch_map && field.branch_map[value] ? field.branch_map[value] : '';
                    return `
                        <div class="wpf-setting-row">
                            <label>After "${escapeHtml(option.label || value)}"</label>
                            <select class="wpf-branch-target" data-option-value="${escapeHtml(value)}">
                                ${targetOptions}
                            </select>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    function bindBranchEditor(field) {
        fieldSettingsPanel.querySelectorAll('.wpf-branch-target').forEach((select) => {
            const optionValue = select.dataset.optionValue || '';
            select.value = field.branch_map && field.branch_map[optionValue] ? field.branch_map[optionValue] : '';

            select.addEventListener('change', (event) => {
                pushHistory();

                if (!field.branch_map || typeof field.branch_map !== 'object') {
                    field.branch_map = {};
                }

                if (event.target.value) {
                    field.branch_map[optionValue] = event.target.value;
                } else {
                    delete field.branch_map[optionValue];
                }

                field.flow_rules = Object.keys(field.branch_map).map((value, index) => {
                    const target = field.branch_map[value];
                    const actionType = target === '__end' ? 'end' : (target === '__action' ? 'action' : (target ? 'jump' : 'next'));
                    return {
                        id: 'flow_' + field.id + '_' + index,
                        priority: (index + 1) * 10,
                        logic: 'AND',
                        conditions: [{ field: field.id, operator: 'equals', value: value }],
                        actions: [{ type: actionType, target: actionType === 'jump' ? target : '' }],
                        stop_processing: true
                    };
                });
            });
        });
    }

    function openFieldSettings(field) {
        const fieldTab = document.querySelector('.wpf-tab[data-target="field"]');
        if (fieldTab) {
            fieldTab.click();
        }

        let html = `
            <div class="wpf-field-settings-shell">
                <div class="wpf-field-settings-header">
                    <div class="wpf-field-settings-kicker">Block Settings</div>
                    <div class="wpf-field-settings-title">${escapeHtml(field.label || formatFieldTypeLabel(field.type))}</div>
                    <div class="wpf-field-settings-meta">${escapeHtml(formatFieldTypeLabel(field.type))}</div>
                </div>

                <div class="wpf-field-settings-card">
                    <div class="wpf-field-settings-card-title">Content</div>
                    <div class="wpf-field-settings-grid">
                        <div class="wpf-setting-row">
                            <label>Field Label</label>
                            <input type="text" class="wpf-live-input" data-key="label" value="${escapeHtml(field.label || '')}">
                        </div>
                        <div class="wpf-setting-row">
                            <label>Field Name</label>
                            <input type="text" class="wpf-live-input" data-key="field_name" value="${escapeHtml(field.field_name || '')}">
                            <p class="wpf-setting-help">Use this in messages as <code>{${escapeHtml(field.field_name || 'field_name')}}</code>.</p>
                        </div>
                    </div>
                </div>

                <div class="wpf-field-settings-card">
                    <div class="wpf-field-settings-card-title">Layout</div>
                    <div class="wpf-field-settings-grid">
                        <div class="wpf-setting-row">
                            <label>CSS Class</label>
                            <input type="text" class="wpf-live-input" data-key="css_class" value="${escapeHtml(field.css_class || '')}">
                        </div>
                        <div class="wpf-setting-row">
                            <label>Field Width</label>
                            <select class="wpf-live-select" data-key="width">
                                <option value="100" ${parseInt(field.width, 10) === 100 ? 'selected' : ''}>100% - Full Width</option>
                                <option value="50" ${parseInt(field.width, 10) === 50 ? 'selected' : ''}>50% - Half Width</option>
                                <option value="33" ${parseInt(field.width, 10) === 33 ? 'selected' : ''}>33% - Third Width</option>
                                <option value="25" ${parseInt(field.width, 10) === 25 ? 'selected' : ''}>25% - Quarter Width</option>
                            </select>
                        </div>
                    </div>
                    <div class="wpf-setting-row">
                        <label class="wpf-toggle-card">
                            <strong>Required Field</strong>
                            <input type="checkbox" class="wpf-live-checkbox" data-key="required" ${field.required ? 'checked' : ''}>
                        </label>
                    </div>
                    <div class="wpf-setting-row">
                        <label class="wpf-toggle-card">
                            <strong>Conversational Field</strong>
                            <input type="checkbox" class="wpf-live-checkbox" data-key="conversational" ${field.conversational ? 'checked' : ''}>
                        </label>
                    </div>
                </div>
            </div>
        `;

        if (field.type === 'file') {
            html += `
                <div class="wpf-field-settings-card">
                    <div class="wpf-field-settings-card-title">Upload Rules</div>
                    <div class="wpf-setting-row">
                        <label>Accepted File Types</label>
                        <input type="text" class="wpf-live-input" data-key="accepted_file_types" value="${escapeHtml(field.accepted_file_types || '.pdf,.jpg,.jpeg,.png,.gif,.webp')}">
                        <p class="wpf-setting-help">Example: .pdf,.jpg,.png</p>
                    </div>
                </div>
            `;
        }

        if (field.type === 'question' || field.type === 'select' || field.type === 'checkboxes' || field.type === 'multiple_choice') {
            html += `
                <div class="wpf-field-settings-card">
                    <div class="wpf-field-settings-card-title">Options</div>
                    ${renderOptionsEditor(field)}
                </div>
            `;
        }

        if (field.type === 'question' || field.type === 'select' || field.type === 'checkboxes' || field.type === 'multiple_choice') {
            html += `
                <div class="wpf-field-settings-card">
                    <div class="wpf-field-settings-card-title">Flow Branching</div>
                    <p class="wpf-setting-help">Choose where the conversation goes after each answer. Branching applies when this field is part of the conversational flow.</p>
                    ${renderBranchEditor(field)}
                </div>
            `;
        }

        fieldSettingsPanel.innerHTML = html;

        fieldSettingsPanel.querySelectorAll('.wpf-live-input').forEach((input) => {
            let pushed = false;

            input.addEventListener('focus', () => {
                pushed = false;
            });

            input.addEventListener('input', (e) => {
                if (!pushed) {
                    pushHistory();
                    pushed = true;
                }

                field[e.target.dataset.key] = e.target.dataset.key === 'field_name' ? slugifyFieldName(e.target.value) : e.target.value;
                if (e.target.dataset.key === 'field_name') {
                    e.target.value = field.field_name;
                }
                renderCanvas();
                const titleNode = fieldSettingsPanel.querySelector('.wpf-field-settings-title');
                if (titleNode && e.target.dataset.key === 'label') {
                    titleNode.textContent = e.target.value || formatFieldTypeLabel(field.type);
                }
            });
        });

        fieldSettingsPanel.querySelectorAll('.wpf-live-checkbox').forEach((input) => {
            input.addEventListener('change', (e) => {
                pushHistory();
                field[e.target.dataset.key] = !!e.target.checked;
                if (e.target.dataset.key === 'conversational') {
                    field.conversation_step = field.conversational ? 'question' : 'final_contact';
                }
                renderCanvas();
            });
        });

        fieldSettingsPanel.querySelectorAll('.wpf-live-select').forEach((input) => {
            input.addEventListener('change', (e) => {
                pushHistory();
                field[e.target.dataset.key] = e.target.dataset.key === 'width' ? parseInt(e.target.value, 10) : e.target.value;
                renderCanvas();
            });
        });

        if (field.type === 'question' || field.type === 'select' || field.type === 'checkboxes' || field.type === 'multiple_choice') {
            bindOptionsEditor(field);
        }

        if (field.type === 'question' || field.type === 'select' || field.type === 'checkboxes' || field.type === 'multiple_choice') {
            bindBranchEditor(field);
        }
    }

    function addField(type, defaultLabel, insertIndex = null) {
        pushHistory();

        const newField = getDefaultField(type, defaultLabel);

        if (typeof insertIndex === 'number' && insertIndex >= 0 && insertIndex <= formSchema.fields.length) {
            formSchema.fields.splice(insertIndex, 0, newField);
        } else {
            formSchema.fields.push(newField);
        }

        activeFieldId = newField.id;
        renderCanvas();
        openFieldSettings(newField);
    }

    document.querySelectorAll('.wpf-field-btn').forEach((btn) => {
        btn.setAttribute('draggable', 'true');

        btn.addEventListener('dragstart', (e) => {
            const payload = JSON.stringify({
                source: 'library',
                type: btn.dataset.type,
                label: btn.dataset.label
            });

            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', payload);
            dropzone.classList.add('drag-over');
        });

        btn.addEventListener('dragend', () => {
            dropzone.classList.remove('drag-over');
        });

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (fieldsSidebar) {
                fieldsSidebar.classList.remove('is-collapsed');
            }
            addField(btn.dataset.type, btn.dataset.label, getPreferredInsertIndex());
        });
    });

    if (toggleFieldsBtn && fieldsSidebar) {
        toggleFieldsBtn.addEventListener('click', (e) => {
            e.preventDefault();
            fieldsSidebar.classList.remove('is-collapsed');
            const fieldsTab = document.querySelector('.wpf-sidebar-toggle-tab[data-drawer-panel="fields"]');
            if (fieldsTab) {
                fieldsTab.click();
            }
        });
    }

    if (toggleStructureBtn && fieldsSidebar) {
        toggleStructureBtn.addEventListener('click', (e) => {
            e.preventDefault();
            fieldsSidebar.classList.remove('is-collapsed');
            const structureTab = document.querySelector('.wpf-sidebar-toggle-tab[data-drawer-panel="structure"]');
            if (structureTab) {
                structureTab.click();
            }
        });
    }

    document.querySelectorAll('.wpf-sidebar-toggle-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.wpf-sidebar-toggle-tab').forEach((item) => item.classList.remove('is-active'));
            document.querySelectorAll('.wpf-drawer-panel').forEach((panel) => panel.classList.remove('is-active'));

            tab.classList.add('is-active');
            const targetPanel = document.querySelector(`.wpf-drawer-panel[data-drawer-panel-content="${tab.dataset.drawerPanel}"]`);
            if (targetPanel) {
                targetPanel.classList.add('is-active');
            }
        });
    });

    if (fieldsSearchInput) {
        fieldsSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            document.querySelectorAll('.wpf-field-btn').forEach((btn) => {
                const label = (btn.dataset.label || '').toLowerCase();
                btn.style.display = label.includes(query) ? '' : 'none';
            });
        });
    }

    dropzone.addEventListener('dragenter', (e) => {
        e.preventDefault();
        dropzone.classList.add('drag-over');
    });

    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        dropzone.classList.add('drag-over');

        applyDropIndicator(getInsertIndexFromPointer(e.clientX, e.clientY));
    });

    dropzone.addEventListener('dragleave', (e) => {
        if (!dropzone.contains(e.relatedTarget)) {
            dropzone.classList.remove('drag-over');
            clearDropIndicator();
        }
    });

    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();

        dropzone.classList.remove('drag-over');

        const payload = e.dataTransfer.getData('text/plain');
        const insertIndex = currentDropIndex !== null ? currentDropIndex : getInsertIndexFromPointer(e.clientX, e.clientY);
        clearDropIndicator();

        if (!payload) {
            return;
        }

        try {
            const data = JSON.parse(payload);
            if (data.source === 'library') {
                addField(data.type, data.label, insertIndex);
            }
        } catch (error) {
            console.error('AJ Forms drop payload invalid', error);
        }
    });

    document.querySelectorAll('.wpf-tab').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            document.querySelectorAll('.wpf-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.wpf-settings-panel').forEach((panel) => panel.classList.remove('active'));

            e.currentTarget.classList.add('active');

            const targetPanel = document.getElementById('wpf-panel-' + e.currentTarget.dataset.target);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });

    document.querySelectorAll('.wpf-inspector-subtab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.wpf-inspector-subtab').forEach((item) => item.classList.remove('is-active'));
            document.querySelectorAll('.wpf-inspector-subpanel').forEach((panel) => panel.classList.remove('is-active'));

            tab.classList.add('is-active');
            const targetPanel = document.querySelector(`.wpf-inspector-subpanel[data-form-subpanel="${tab.dataset.formSubtab}"]`);
            if (targetPanel) {
                targetPanel.classList.add('is-active');
            }
        });
    });

    [
        'wpf-form-theme',
        'wpf-form-background-mode',
        'wpf-form-background-color',
        'wpf-form-gradient-start',
        'wpf-form-gradient-end',
        'wpf-form-primary-color',
        'wpf-form-text-color',
        'wpf-form-input-background',
        'wpf-form-input-border',
        'wpf-form-border-radius',
        'wpf-form-button-alignment',
        'wpf-form-submit-text',
        'wpf-form-use-label-placeholders'
    ].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) {
            return;
        }

        const eventName = input.type === 'range' || input.type === 'color' || input.type === 'checkbox' ? 'input' : 'change';
        input.addEventListener(eventName, () => {
            formSchema.settings.form_theme = document.getElementById('wpf-form-theme')?.value || 'clean';
            formSchema.settings.background_mode = document.getElementById('wpf-form-background-mode')?.value || 'solid';
            formSchema.settings.background_color = document.getElementById('wpf-form-background-color')?.value || '#ffffff';
            formSchema.settings.background_gradient_start = document.getElementById('wpf-form-gradient-start')?.value || '#ffffff';
            formSchema.settings.background_gradient_end = document.getElementById('wpf-form-gradient-end')?.value || '#f3f7fb';
            formSchema.settings.primary_color = document.getElementById('wpf-form-primary-color')?.value || '#0f7ac6';
            formSchema.settings.text_color = document.getElementById('wpf-form-text-color')?.value || '#1f2937';
            formSchema.settings.input_background = document.getElementById('wpf-form-input-background')?.value || '#ffffff';
            formSchema.settings.input_border_color = document.getElementById('wpf-form-input-border')?.value || '#d7dce3';
            formSchema.settings.border_radius = parseInt(document.getElementById('wpf-form-border-radius')?.value || '16', 10);
            formSchema.settings.button_alignment = document.getElementById('wpf-form-button-alignment')?.value || 'left';
            formSchema.settings.submit_text = document.getElementById('wpf-form-submit-text')?.value?.trim() || 'Submit';
            formSchema.settings.use_label_placeholders = !!document.getElementById('wpf-form-use-label-placeholders')?.checked;

            const radiusValue = document.getElementById('wpf-form-border-radius-value');
            if (radiusValue) {
                radiusValue.textContent = String(formSchema.settings.border_radius);
            }

            renderCanvas();
        });
    });

    if (formSettingsToggle && formSettingsMenu) {
        formSettingsToggle.addEventListener('click', (e) => {
            e.preventDefault();
            formSettingsMenu.classList.toggle('is-open');
        });

        document.addEventListener('click', (e) => {
            if (!formSettingsMenu.contains(e.target) && !formSettingsToggle.contains(e.target)) {
                formSettingsMenu.classList.remove('is-open');
            }
        });

        formSettingsMenu.querySelectorAll('.wpf-settings-menu-item').forEach((button) => {
            button.addEventListener('click', () => {
                formSettingsMenu.classList.remove('is-open');
                activateFormSettingsSection(button.dataset.section || 'basics');
            });
        });
    }

    formSectionTabs.forEach((button) => {
        button.addEventListener('click', () => {
            activateFormSettingsSection(button.dataset.settingsSectionTab || 'basics');
        });
    });

    const confirmationModeInput = document.getElementById('wpf-form-confirmation-mode');
    if (confirmationModeInput) {
        confirmationModeInput.addEventListener('change', () => {
            formSchema.settings.confirmation_mode = confirmationModeInput.value || 'default';
            updateConfirmationModePanels();
        });
    }

    const addConfirmationRuleBtn = document.getElementById('wpf-add-confirmation-rule');
    if (addConfirmationRuleBtn) {
        addConfirmationRuleBtn.addEventListener('click', () => {
            formSchema.settings.confirmation_rules = normalizeConfirmationRules(formSchema.settings.confirmation_rules || []);
            const newRule = createDefaultConfirmationRule();
            formSchema.settings.confirmation_rules.push(newRule);
            renderConfirmationRules(newRule.id);
        });
    }

    activateFormSettingsSection('basics');
    renderConfirmationRules();
    updateConfirmationModePanels();

    if (undoBtn) {
        undoBtn.addEventListener('click', (e) => {
            e.preventDefault();
            undoAction();
        });
    }

    if (redoBtn) {
        redoBtn.addEventListener('click', (e) => {
            e.preventDefault();
            redoAction();
        });
    }

    document.addEventListener('keydown', (e) => {
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const cmdOrCtrl = isMac ? e.metaKey : e.ctrlKey;

        if (!cmdOrCtrl) {
            return;
        }

        if (e.key.toLowerCase() === 'z' && !e.shiftKey) {
            e.preventDefault();
            undoAction();
        }

        if (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey)) {
            e.preventDefault();
            redoAction();
        }
    });

    const saveBtn = document.getElementById('wpf-save-form-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', (e) => {
            e.preventDefault();
            saveForm('published');
        });
    }

    const draftBtn = document.getElementById('wpf-save-draft-btn');
    if (draftBtn) {
        draftBtn.addEventListener('click', (e) => {
            e.preventDefault();
            saveForm('draft');
        });
    }

    function saveForm(status) {
        if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
            window.tinyMCE.triggerSave();
        }

        const titleInput = document.getElementById('wpf-form-title');
        const submitTextInput = document.getElementById('wpf-form-submit-text');
        const notificationsInput = document.getElementById('wpf-form-notifications');
        const notificationEmailInput = document.getElementById('wpf-form-notification-email');
        const notificationSubjectInput = document.getElementById('wpf-form-notification-subject');
        const notificationBodyInput = document.getElementById('wpf_form_notification_body');
        const notificationFromNameInput = document.getElementById('wpf-form-notification-from-name');
        const notificationFromEmailInput = document.getElementById('wpf-form-notification-from-email');
        const notificationReplyToInput = document.getElementById('wpf-form-notification-reply-to');
        const descriptionInput = document.getElementById('wpf-form-description');
        const successMessageInput = document.getElementById('wpf-form-success-message');
        const buttonAlignmentInput = document.getElementById('wpf-form-button-alignment');
        const confirmationModeInput = document.getElementById('wpf-form-confirmation-mode');
        const confirmationTypeInput = document.getElementById('wpf-form-confirmation-type');
        const redirectUrlInput = document.getElementById('wpf-form-redirect-url');
        const useLabelsAsPlaceholdersInput = document.getElementById('wpf-form-use-label-placeholders');
        const customCssInput = document.getElementById('wpf-form-custom-css');
        const asanaTaskEnabledInput = document.getElementById('wpf-form-asana-task-enabled');
        const asanaTaskNameInput = document.getElementById('wpf-form-asana-task-name');
        const asanaTaskNotesInput = document.getElementById('wpf-form-asana-task-notes');
        const asanaProjectGidInput = document.getElementById('wpf-form-asana-project-gid');
        const asanaAssigneeGidInput = document.getElementById('wpf-form-asana-assignee-gid');
        const asanaDueDateInput = document.getElementById('wpf-form-asana-due-date');
        const stripeEnabledInput = document.getElementById('wpf-form-stripe-enabled');
        const stripeAmountInput = document.getElementById('wpf-form-stripe-amount');
        const stripeCurrencyInput = document.getElementById('wpf-form-stripe-currency');
        const stripeDescriptionInput = document.getElementById('wpf-form-stripe-description');
        const formThemeInput = document.getElementById('wpf-form-theme');
        const backgroundModeInput = document.getElementById('wpf-form-background-mode');
        const backgroundColorInput = document.getElementById('wpf-form-background-color');
        const gradientStartInput = document.getElementById('wpf-form-gradient-start');
        const gradientEndInput = document.getElementById('wpf-form-gradient-end');
        const primaryColorInput = document.getElementById('wpf-form-primary-color');
        const textColorInput = document.getElementById('wpf-form-text-color');
        const inputBackgroundInput = document.getElementById('wpf-form-input-background');
        const inputBorderInput = document.getElementById('wpf-form-input-border');
        const borderRadiusInput = document.getElementById('wpf-form-border-radius');

        const title = titleInput ? titleInput.value.trim() : '';

        if (!title || title === 'Untitled Form') {
            window.alert('Please enter a unique form title.');
            return;
        }

        formSchema.title = title;
        formSchema.settings.submit_text = submitTextInput ? (submitTextInput.value.trim() || 'Submit') : 'Submit';
        formSchema.settings.notifications_enabled = notificationsInput ? !!notificationsInput.checked : true;
        formSchema.settings.notification_email = notificationEmailInput ? notificationEmailInput.value.trim() : '';
        formSchema.settings.notification_subject = notificationSubjectInput ? (notificationSubjectInput.value.trim() || 'New submission for {form_title}') : 'New submission for {form_title}';
        formSchema.settings.notification_body = notificationBodyInput ? (notificationBodyInput.value.trim() || '{submission_table}{submission_details_table}') : '{submission_table}{submission_details_table}';
        formSchema.settings.notification_from_name = notificationFromNameInput ? notificationFromNameInput.value.trim() : '';
        formSchema.settings.notification_from_email = notificationFromEmailInput ? notificationFromEmailInput.value.trim() : '';
        formSchema.settings.notification_reply_to = notificationReplyToInput ? notificationReplyToInput.value.trim() : '';
        formSchema.settings.form_description = descriptionInput ? descriptionInput.value.trim() : '';
        formSchema.settings.success_message = successMessageInput ? (successMessageInput.value.trim() || 'Form submitted successfully.') : 'Form submitted successfully.';
        formSchema.settings.button_alignment = buttonAlignmentInput ? (buttonAlignmentInput.value || 'left') : 'left';
        formSchema.settings.confirmation_mode = confirmationModeInput ? (confirmationModeInput.value || 'default') : 'default';
        formSchema.settings.confirmation_type = confirmationTypeInput ? (confirmationTypeInput.value || 'message') : 'message';
        formSchema.settings.redirect_url = redirectUrlInput ? redirectUrlInput.value.trim() : '';
        formSchema.settings.confirmation_rules = collectConfirmationRulesFromDom();
        formSchema.settings.use_label_placeholders = useLabelsAsPlaceholdersInput ? !!useLabelsAsPlaceholdersInput.checked : false;
        formSchema.settings.custom_css = customCssInput ? customCssInput.value.trim() : '';
        formSchema.settings.asana_task_enabled = asanaTaskEnabledInput ? !!asanaTaskEnabledInput.checked : false;
        formSchema.settings.asana_task_name = asanaTaskNameInput ? (asanaTaskNameInput.value.trim() || 'New form submission: {form_title}') : 'New form submission: {form_title}';
        formSchema.settings.asana_task_notes = asanaTaskNotesInput ? (asanaTaskNotesInput.value.trim() || 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}') : 'Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}';
        formSchema.settings.asana_project_gid = asanaProjectGidInput ? asanaProjectGidInput.value.trim() : '';
        formSchema.settings.asana_assignee_gid = asanaAssigneeGidInput ? asanaAssigneeGidInput.value.trim() : '';
        formSchema.settings.asana_due_date = asanaDueDateInput ? (asanaDueDateInput.value || 'today') : 'today';
        formSchema.settings.stripe_enabled = stripeEnabledInput ? !!stripeEnabledInput.checked : false;
        formSchema.settings.stripe_amount = stripeAmountInput ? (stripeAmountInput.value || '') : '';
        formSchema.settings.stripe_currency = stripeCurrencyInput ? (stripeCurrencyInput.value || 'usd') : 'usd';
        formSchema.settings.stripe_description = stripeDescriptionInput ? (stripeDescriptionInput.value.trim() || 'Payment for {form_title}') : 'Payment for {form_title}';
        formSchema.settings.form_theme = formThemeInput ? (formThemeInput.value || 'clean') : 'clean';
        formSchema.settings.background_mode = backgroundModeInput ? (backgroundModeInput.value || 'solid') : 'solid';
        formSchema.settings.background_color = backgroundColorInput ? (backgroundColorInput.value || '#ffffff') : '#ffffff';
        formSchema.settings.background_gradient_start = gradientStartInput ? (gradientStartInput.value || '#ffffff') : '#ffffff';
        formSchema.settings.background_gradient_end = gradientEndInput ? (gradientEndInput.value || '#f3f7fb') : '#f3f7fb';
        formSchema.settings.primary_color = primaryColorInput ? (primaryColorInput.value || '#0f7ac6') : '#0f7ac6';
        formSchema.settings.text_color = textColorInput ? (textColorInput.value || '#1f2937') : '#1f2937';
        formSchema.settings.input_background = inputBackgroundInput ? (inputBackgroundInput.value || '#ffffff') : '#ffffff';
        formSchema.settings.input_border_color = inputBorderInput ? (inputBorderInput.value || '#d7dce3') : '#d7dce3';
        formSchema.settings.border_radius = borderRadiusInput ? parseInt(borderRadiusInput.value || '16', 10) : 16;

        const payload = {
            action: 'ajf_save_form',
            nonce: window.ajFormsBuilder ? window.ajFormsBuilder.nonce_save : '',
            form_id: formId,
            title: title,
            schema: JSON.stringify(formSchema),
            status: status
        };

        const formData = new FormData();
        Object.keys(payload).forEach((key) => formData.append(key, payload[key]));

        fetch(window.ajFormsBuilder ? window.ajFormsBuilder.ajaxurl : ajaxurl, {
            method: 'POST',
            body: formData
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    formId = data.data.form_id;
                    if (previewLink) {
                        if (previewLink.tagName.toLowerCase() === 'a') {
                            previewLink.href = data.data.preview_url;
                        } else {
                            previewLink.outerHTML = `<a href="${data.data.preview_url}" id="wpf-preview-form-link" class="wpf-btn wpf-btn-secondary" target="_blank" rel="noopener noreferrer">Preview</a>`;
                        }
                    }
                    window.alert(status === 'draft' ? 'Draft saved successfully.' : 'Form saved successfully.');

                    if (status !== 'draft') {
                        const formsUrl = (window.ajFormsBuilder && window.ajFormsBuilder.formsUrl)
                            ? window.ajFormsBuilder.formsUrl
                            : '/wp-admin/admin.php?page=ajforms';
                        window.location.href = formsUrl;
                    }
                } else {
                    window.alert(data.data || 'Unable to save form.');
                }
            })
            .catch(() => {
                window.alert('Unable to save form.');
            });
    }

    updateUndoRedoButtons();
    renderCanvas();

    if (activeFieldId) {
        const initialField = formSchema.fields.find((field) => field.id === activeFieldId);
        if (initialField) {
            openFieldSettings(initialField);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAJFormsBuilder);
} else {
    initAJFormsBuilder();
}
