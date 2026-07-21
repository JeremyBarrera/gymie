<?php

use App\Models\Enquiry;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;

return [
    Enquiry::class => ['followUps'],

    Service::class => ['plans'],

    Member::class => ['subscriptions'],

    Plan::class => ['subscriptions'],

    Subscription::class => ['invoices'],

    User::class => ['followUps', 'enquiries'],

    Location::class => ['members'],

];
