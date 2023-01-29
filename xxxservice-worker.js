
/*
 * ==========================================================
 * SERVICE WORKER 
 * ==========================================================
 *
 * Service Worker of Support Board users area
 *
*/

importScripts('https://js.pusher.com/beams/service-worker.js');

var sb_push_link;

PusherPushNotifications.onNotificationReceived = ({ pushEvent, payload }) => {
    sb_push_link = payload.notification.deep_link;
    pushEvent.waitUntil(self.registration.showNotification(payload.notification.title, {body: payload.notification.body, icon: payload.notification.icon, data: payload.data }));
};

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    event.waitUntil(clients.matchAll({
        type: 'window',
        includeUncontrolled: true
    }).then((clientList) => {
        for (var i = 0; i < clientList.length; i++) {
            if (clientList[i].url.split('?')[0] == sb_push_link) {
                clientList[i].postMessage('sb-open-chat');
                return clientList[i].focus();
            }
        }
        if (sb_push_link && clients.openWindow) return clients.openWindow(sb_push_link);
    }));
});