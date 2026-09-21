/** Official additionalViewModelModules integration; no Core code patching. */
export function bootstrap(app) {
    const identifier = new URL(location.href).searchParams.get('fmpFocus');
    if (!identifier || identifier.length > 200 || !window.TYPO3?.settings?.FormManagerPlus?.enabled) return;
    app.getPublisherSubscriber().subscribe('view/ready', () => {
        queueMicrotask(() => {
            const find = node => {
                if (node.get('identifier') === identifier) return node;
                for (const child of node.get('renderables') || []) { const found = find(child); if (found) return found; }
                return null;
            };
            const node = find(app.getRootFormElement());
            if (node) app.getPublisherSubscriber().publish('view/stage/element/clicked', [node.get('__identifierPath')]);
        });
    });
}
