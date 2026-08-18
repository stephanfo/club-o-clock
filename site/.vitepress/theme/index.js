// `theme-without-fonts` plutôt que `theme` : le thème par défaut embarque Inter en quatorze
// fichiers (cyrillique, grec et vietnamien compris) qu'aucune page de ce corpus n'emploie.
// Le projet auto-héberge Manrope et ne charge rien d'autre — c'est une exigence, pas une
// optimisation : aucun appel réseau vers un tiers, y compris pour une police.
import DefaultTheme from 'vitepress/theme-without-fonts'
import './fonts.css'
import './custom.css'

export default DefaultTheme
