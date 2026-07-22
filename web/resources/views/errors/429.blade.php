@extends('errors.layout')
@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('You’ve made a lot of requests in a short time. Please wait a moment and try again.'))
