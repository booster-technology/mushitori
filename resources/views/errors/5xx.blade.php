{{-- 個別の画面を用意していない 5xx エラー(502 など)の共通画面。英語の標準画面が表示されないようにする --}}
@extends('errors.layout')

@section('code', $exception->getStatusCode())
@section('title', '問題が発生しました')
@section('message', '申し訳ありません。システムで問題が発生しました。時間をおいて再度お試しください。')
