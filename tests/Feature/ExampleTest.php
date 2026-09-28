<?php

test('guests are redirected to login from the application root', function () {
    $this->get('/')->assertRedirect('/journal');
    $this->get('/journal')->assertRedirect('/login');
});
