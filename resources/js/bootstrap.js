// resources/js/bootstrap.js
import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Echo (Reverb) подключается лениво — см. resources/js/echo.js::getEcho().
// Раньше соединение открывалось здесь на загрузку каждой страницы, и консоль
// заваливалась ошибками WebSocket на страницах без чата (лендинг, регистрация).
