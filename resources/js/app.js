import Alpine from 'alpinejs';
import composer from './composer';

window.Alpine = Alpine;

Alpine.data('composer', composer);

Alpine.start();
