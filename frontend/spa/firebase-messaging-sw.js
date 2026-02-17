/* eslint-disable no-undef */
/* global firebase, self */

importScripts('https://www.gstatic.com/firebasejs/10.0.0/firebase-app-compat.js')
importScripts('https://www.gstatic.com/firebasejs/10.0.0/firebase-messaging-compat.js')

firebase.initializeApp({
  apiKey: 'AIzaSyBY9luMHcyhWBwzx92gFHBmiYrVey_PhTI',
  authDomain: 'crypt-talk-d6be2.firebaseapp.com',
  projectId: 'crypt-talk-d6be2',
  storageBucket: 'crypt-talk-d6be2.firebasestorage.app',
  messagingSenderId: '839179457213',
  appId: '1:839179457213:web:a90e0349882656b1db5dd4',
})

const messaging = firebase.messaging()

messaging.onBackgroundMessage((payload) => {
  self.registration.showNotification(payload.notification.title, {
    body: payload.notification.body,
    icon: '/icons/favicon-128x128.png',
  })
})
