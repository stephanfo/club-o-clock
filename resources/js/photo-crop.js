// Recadrage de la photo du trombinoscope (#72, PRD §4.23.6) — dialog ouvert à chaque dépôt, déjà
// cadré comme le recadrage automatique du serveur (centré pour un paysage, tiers supérieur pour un
// portrait) : « Valider » sans toucher donne le même résultat qu'avant.
//
// À la validation, le navigateur dessine le carré choisi dans un canvas et n'envoie QUE lui, en JPEG
// de 1024 px : une photo de téléphone de 10 à 20 Mo ne pèse plus que quelques centaines de Ko, et
// la limite de 5 Mo du serveur ne gêne plus personne. L'original ne quitte jamais l'appareil, et le
// canvas n'emporte aucune métadonnée. Le serveur contrôle et réencode ce carré comme toute photo
// (TrombiService::storePhoto) : c'est lui qui garantit le format, le navigateur ne fait que réduire.
// Rien ne part tant que l'adhérent n'a pas validé.
//
// Repère : l'image telle que le navigateur l'affiche — orientation EXIF appliquée, comme le fait le
// serveur — puis tournée de `rot` quarts de tour HORAIRES. `naturalWidth/Height` d'un <img> y sont
// déjà redressés (image-orientation: from-image, défaut de tous les navigateurs actuels).

// L'original n'est que lu ici, jamais envoyé : la borne protège la mémoire du téléphone, pas le
// serveur. HEIC/HEIF accepté quand le navigateur sait le décoder (Safari) : il part en JPEG.
const MAX_SOURCE = 40 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
const OUT = 1024;      // côté du carré envoyé (le serveur en tire 512 et 192)
const QUALITY = 0.9;
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
            this.error = !TYPES.includes(file.type) ? 'Formats acceptés : JPEG, PNG, WebP ou HEIC.'
                : file.size > MAX_SOURCE ? 'Cette photo dépasse 40 Mo.' : '';
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

        // Le carré choisi, dessiné à OUT px. Repère : l'image tournée de `rot` quarts de tour, dont
        // le cadre couvre [left, left + side] × [top, top + side]. drawImage applique l'orientation
        // EXIF comme l'<img> de l'aperçu : ce qui part est exactement ce que montre le cercle.
        render() {
            const s = this.scale;
            const side = this.stage / s;
            const left = ((this.W * s) / 2 - this.stage / 2 - this.ox) / s;
            const top = ((this.H * s) / 2 - this.stage / 2 - this.oy) / s;

            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = OUT;
            const g = canvas.getContext('2d');
            g.fillStyle = '#fff'; // fond d'un PNG transparent, comme côté serveur
            g.fillRect(0, 0, OUT, OUT);
            g.imageSmoothingQuality = 'high';
            g.scale(OUT / side, OUT / side);
            g.translate(-left, -top);
            g.translate(this.W / 2, this.H / 2);
            g.rotate((this.rot * Math.PI) / 2);
            g.drawImage(this.$refs.img, -this.nw / 2, -this.nh / 2);

            return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
        },

        async confirm() {
            if (this.busy || !this.file) return;
            this.busy = true;
            this.error = '';
            this.progress = 0;

            let blob = null;
            try {
                blob = await this.render();
            } catch (e) {
                blob = null;
            }
            // Pas de repli muet : sans canvas exploitable, on le dit plutôt que d'envoyer l'original
            // (que le serveur refuserait au-delà de 5 Mo, et sans le cadrage choisi).
            if (!blob) {
                this.busy = false;
                this.error = 'Ce navigateur n’a pas pu préparer la photo. Essaie avec une autre photo ou un autre navigateur.';
                return;
            }

            const file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
            this.$wire.upload('photo', file,
                () => { this.busy = false; this.close(); },
                () => { this.busy = false; this.error = 'L’envoi a échoué. Vérifie la connexion et réessaie.'; },
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
