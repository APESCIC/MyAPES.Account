// TinyMCE 8 on textarea[data-rich-text] (#483). Free plan, open-source
// plugins only. The server sanitises whatever is submitted (#484).

export const RICH_TEXT_PLUGINS = [
    'accordion', 'advlist', 'anchor', 'autolink', 'autoresize', 'autosave', 'charmap', 'code', 'codesample',
    'directionality', 'emoticons', 'fullscreen', 'help', 'image', 'importcss', 'insertdatetime', 'link',
    'lists', 'media', 'nonbreaking', 'pagebreak', 'preview', 'quickbars', 'save', 'searchreplace', 'table',
    'visualblocks', 'visualchars', 'wordcount',
];

export const RICH_TEXT_TOOLBAR = 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat | code';

export const richTextConfig = (selector, isDark) => ({
    selector,
    plugins: RICH_TEXT_PLUGINS,
    toolbar: RICH_TEXT_TOOLBAR,
    menubar: false,
    promotion: false,
    min_height: 220,
    autoresize_bottom_margin: 16,
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    link_default_target: '_blank',
    invalid_elements: 'script,iframe,object,embed,style',
    skin: isDark ? 'oxide-dark' : 'oxide',
    content_css: isDark ? 'dark' : 'default',
    setup: (editor) => {
        // A hidden required textarea blocks form submission; the server validates instead.
        editor.on('init', () => editor.getElement()?.removeAttribute('required'));
    },
});

export const initRichTextEditors = (win = window, doc = document) => {
    if (!win.tinymce || !doc.querySelector('textarea[data-rich-text]')) {
        return false;
    }

    win.tinymce.init(richTextConfig('textarea[data-rich-text]', doc.documentElement.dataset.theme === 'dark'));

    return true;
};
