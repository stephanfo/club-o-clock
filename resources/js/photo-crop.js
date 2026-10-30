// Recadrage de la photo du trombinoscope (#72, PRD §4.23.6) — dialog ouvert à chaque dépôt, déjà
// cadré comme le recadrage automatique du serveur (centré pour un paysage, tiers supérieur pour un
// portrait) : « Valider » sans toucher donne le même résultat qu'avant.
//
// Le navigateur ne fait que PROPOSER un rectangle : il pose `crop` sur le composant Livewire, puis
// envoie le fichier ; le serveur redresse, tourne, recadre et réencode (TrombiService::storePhoto),
// en bornant tout ce qui arrive d'ici. Rien ne part tant que l'adhérent n'a pas validé.
//
// Repère : l'image telle que le navigateur l'affiche — orientation EXIF appliquée, comme le fait le
// serveur — puis tournée de `rot` quarts de tour HORAIRES. `naturalWidth/Height` d'un <img> y sont
// déjà redressés (image-orientation: from-image, défaut de tous les navigateurs actuels).

const MAX_BYTES = 5 * 1024 * 1024; // aligné sur TrombiService::MAX_BYTES
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const ZOOM_MAX = 4;

function photoCrop() {
    return {
        open: false,
        busy: false,
        error: '',
        src: null,
        file: null,
        nw: 0, nh: 0,       // dimensions redressées de l'image
        rot: 0,             // quarts de tour horaires
        zoom: 1,
        ox: 0, oy: 0,       // décalage du centre de l'image par rapport au centre du cadre, en px
        stage: 280,         // côté du cadre affiché, en px
        progress: 0,
        pointers: new Map(),
        pinch: null,

        choose(event) {
            const file = event.target.files?.[0];
            event.target.value = ''; // rechoisir le même fichier rouvre le dialog
            if (!file) return;
            // Refus immédiats, sans envoyer 5 Mo pour rien ; le serveur revérifie de toute façon.
            this.error = !TYPES.includes(file.type) ? 'Formats acceptés : JPEG, PNG ou WebP.'
                : file.size > MAX_BYTES ? 'La photo dépasse 5 Mo.' : '';
            if (this.error) return;
            this.file = file;
            this.src = URL.createObjectURL(file);
            this.rot = 0;
            this.open = true;
        },

        unreadable() {
            this.close();
            this.error = 'Cette photo n’a pas pu être lue.';
        },

        loaded(img) {
            this.nw = img.naturalWidth;
            this.nh = img.naturalHeight;
            this.$nextTick(() => {
                this.stage = this.$refs.stage?.clientWidth || 280;
                this.reset();
            });
        },

        // Dimensions de l'image une fois tournée.
        get W() { return this.rot % 2 ? this.nh : this.nw; },
        get H() { return this.rot % 2 ? this.nw : this.nh; },
        get scale() { return (this.stage / Math.min(this.W, this.H)) * this.zoom; },

        // Cadrage automatique du serveur : centré, ou calé sur le tiers supérieur d'un portrait.
        reset() {
            this.zoom = 1;
            const s = this.scale;
            const side = Math.min(this.W, this.H);
            const left = (this.W - side) / 2;
            const top = (this.H - side) / 3;
            this.ox = (this.W * s) / 2 - this.stage / 2 - left * s;
            this.oy = (this.H * s) / 2 - this.stage / 2 - top * s;
            this.clamp();
        },

        clamp() {
            const mx = Math.max(0, (this.W * this.scale - this.stage) / 2);
            const my = Math.max(0, (this.H * this.scale - this.stage) / 2);
            this.ox = Math.min(mx, Math.max(-mx, this.ox));
            this.oy = Math.min(my, Math.max(-my, this.oy));
        },

        setZoom(z) {
            const next = Math.min(ZOOM_MAX, Math.max(1, Number(z)));
            // Le point au centre du cadre reste au centre.
            const k = next / this.zoom;
            this.zoom = next;
            this.ox *= k;
            this.oy *= k;
            this.clamp();
        },

        rotate() {
            this.rot = (this.rot + 1) % 4;
            this.reset();
        },

        down(e) {
            this.$refs.stage.setPointerCapture(e.pointerId);
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pointers.size === 2) {
                const [a, b] = [...this.pointers.values()];
                this.pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), zoom: this.zoom };
            }
        },

        move(e) {
            const prev = this.pointers.get(e.pointerId);
            if (!prev) return;
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pointers.size === 2 && this.pinch) {
                const [a, b] = [...this.pointers.values()];
                this.setZoom(this.pinch.zoom * (Math.hypot(a.x - b.x, a.y - b.y) / this.pinch.d));
                return;
            }
            this.ox += e.clientX - prev.x;
            this.oy += e.clientY - prev.y;
            this.clamp();
        },

        up(e) {
            this.pointers.delete(e.pointerId);
            if (this.pointers.size < 2) this.pinch = null;
        },

        wheel(e) {
            this.setZoom(this.zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1));
        },

        // Style de l'<img> : tourné autour de son centre, posé au centre du cadre + décalage.
        get imgStyle() {
            const s = this.scale;
            return `width:${this.nw * s}px;height:${this.nh * s}px;`
                + `transform:translate(-50%,-50%) translate(${this.ox}px,${this.oy}px) rotate(${this.rot * 90}deg)`;
        },

        // Rectangle choisi, en fractions de l'image tournée (cf. TrombiService::storePhoto).
        crop() {
            const s = this.scale;
            const left = ((this.W * s) / 2 - this.stage / 2 - this.ox) / s;
            const top = ((this.H * s) / 2 - this.stage / 2 - this.oy) / s;
            const clip = (v) => Math.min(1, Math.max(0, v));
            return { x: clip(left / this.W), y: clip(top / this.H), s: clip(1 / this.zoom), r: this.rot * 90 };
        },

        confirm() {
            if (this.busy || !this.file) return;
            this.busy = true;
            this.error = '';
            this.progress = 0;
            // Différé (pas de requête) : il part avec la requête qui termine l'envoi du fichier, où le
            // serveur le lit avant de traiter la photo (TrombiSettings::updatedPhoto).
            this.$wire.crop = this.crop();
            this.$wire.upload('photo', this.file,
                () => { this.busy = false; this.close(); },
                () => { this.busy = false; this.error = 'L’envoi a échoué. Réessaie, ou choisis une photo plus légère.'; },
                (e) => { this.progress = e.detail.progress; },
            );
        },

        close() {
            if (this.busy) return;
            this.open = false;
            if (this.src) URL.revokeObjectURL(this.src);
            this.src = null;
            this.file = null;
            this.pointers.clear();
        },
    };
}

document.addEventListener('alpine:init', () => window.Alpine.data('photoCrop', photoCrop));
